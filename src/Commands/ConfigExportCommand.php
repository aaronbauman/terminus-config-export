<?php

namespace Pantheon\TerminusConfigExport\Commands;

use Pantheon\Terminus\Commands\Remote\SSHBaseCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Models\Workflow;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Class ConfigExportCommand terminus export config convenience wrapper.
 *
 * @package Pantheon\TerminusConfigExport\Commands
 */
class ConfigExportCommand extends SSHBaseCommand implements SiteAwareInterface {

  use SiteAwareTrait;
  use WorkflowProcessingTrait;

  const DEFAULT_WORKFLOW_TIMEOUT = 180;

  /**
   * Site.
   *
   * @var \Pantheon\Terminus\Models\Site
   */
  protected $site;

  /**
   * Env.
   *
   * @var \Pantheon\Terminus\Models\Environment
   */
  protected $env;

  /**
   * Options array.
   *
   * @var array
   */
  protected $options;

  /**
   * Export config from remote environment to local.
   *
   * @authorize
   *
   * @command site:config-export-remote
   * @aliases cexr sexr scexr site:cexr
   *
   * @param string $site_env The site UUID or machine name and environment
   *   (<site>.<env>)
   *
   * @option string $destination Local destination directory to sync files. Defaults to drush config directory, or cwd if not found.
   * @option string $remote-destination Remote destination directory to sync files within private:// path. Defaults to 'config-export'.
   * @usage <site.env> Exports config from <site.env> to <destination>
   */

  public function configExportRemote($site_env, $options = ['destination' => '', 'remote-destination' => 'config-export']) {
    $this->prepareEnvironment($site_env);
    [$this->site, $this->env] = $this->getSiteEnv($site_env);
    $sftp = $this->env->sftpConnectionInfo();
    $remote_destination = trim($options['remote-destination'], '/');
    // For some reason sshCommand doesn't work for this:
    $this->log()->notice('Verifying remote destination.');
    passthru($sftp['command'] . " << EOF
mkdir files/private/{$remote_destination}
bye
EOF 2>/dev/null");
    $this->log()->notice('Exporting remote config.');
    $this->executeCommand(['drush', 'cex', '--destination=private://' . $remote_destination]);
    $destination = $options['destination'];
    $this->log()->notice('Verifying local destination.');
    if (empty($options['destination'])) {
      $output = [];
      exec('drush gfd 2>/dev/null', $output);
      if (empty($output)) {
        $destination = getcwd();
      }
      else {
        $destination = $output[0];
      }
    }
    $destination = rtrim($destination, '/');
    $this->log()->notice('Rsyncing config from remote to local.');
    $this->rsync($site_env, ':files/private/' . $remote_destination . '/', $destination);
    $this->log()->notice('Cleaning up file permissions.');
    passthru('chmod 644 ' . $destination . '/*.yml');
  }

  /**
   * Call rsync to or from the specified site.
   *
   * @param string $site_env_id Remote site
   * @param string $src Source path to copy from. Start with ":" for remote.
   * @param string $dest Destination path to copy to. Start with ":" for remote.
   * @param boolean $ignoreIfNotExists Silently fail and do not return error if remote source does not exist.
   */
  public function rsync($site_env_id, $src, $dest, $ignoreIfNotExists = true)
  {
    list($site, $env) = $this->getSiteEnv($site_env_id);
    $env_id = $env->getName();

    $siteInfo = $site->serialize();
    $site_id = $siteInfo['id'];

    $siteAddress = "$env_id.$site_id@appserver.$env_id.$site_id.drush.in:";

    $src = preg_replace('/^:/', $siteAddress, $src);
    $dest = preg_replace('/^:/', $siteAddress, $dest);

    $this->log()->notice('Rsync {src} => {dest}', ['src' => $src, 'dest' => $dest]);
    $status = 0;
    $command = "rsync -rlIvz --ipv4 --exclude=.git -e 'ssh -p 2222 -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no' $src $dest";
    passthru($command, $status);
    if (!$ignoreIfNotExists && in_array($status, [0, 23]))
    {
      throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $status]);
    }

    return $status;
  }

  /**
   * Export and commit config.
   *
   * @authorize
   *
   * @command site:config-export
   * @aliases cex sex scex site:cex
   *
   * @param string $site_env The site UUID or machine name and environment
   *   (<site>.<env>)
   *
   * @option string $message Commit message
   * @option int $timeout How long to wait for sub-jobs to complete, in seconds.
   * @usage <site.env> Exports and commits config to <site.env>.
   */
  public function configExport($site_env, $options = ['message' => 'Config export', 'timeout' => self::DEFAULT_WORKFLOW_TIMEOUT]) {
    $this->options = $options;
    if (!$this->hasConfigDifferences($site_env)) {
      return;
    }
    [$this->site, $this->env] = $this->getSiteEnv($site_env);
    $workflow = $this->setSftp();
    $this->drushCex($workflow);
    $workflow = $this->envCommit();
    $this->setGit($workflow);
  }

  protected function hasConfigDifferences($site_env) {
    $output = $code = NULL;
    exec("terminus drush $site_env cst --no-ansi -n", $output, $code);
    return !empty($output);
  }

  protected function setGit(Workflow $workflow = NULL) {
    if ($workflow) {
      $this->wait($workflow);
    }
    try {
      $this->log()->notice('Changing connection mode back to git.');
      $workflow = $this->env->changeConnectionMode('git');
    }
    catch (TerminusException $e) {
      $message = $e->getMessage();
      if (strpos($message, 'git') !== FALSE) {
        $this->log()->notice($message);
        return;
      }
      throw $e;
    }
    if ($workflow) {
      $workflow = $this->processWorkflow($workflow);
      if ($workflow) {
        $this->log()->notice($workflow->getMessage());
      }
    }
  }

  protected function envCommit() {
    $startTime = time();
    while (count((array) $this->env->diffstat()) === 0) {
      $this->log()
        ->notice('Waiting for site config to be available on filesystem.');
      if ($startTime + $this->options['timeout'] < time()) {
        $this->log()
          ->error('Failed to detect any pending changes');
        return;
      }
      // Wait a bit, then spin some more
      sleep(5);
    }
    $this->log()->notice('Committing site configuration to version control.');
    $workflow = $this->processWorkflow($this->env->commitChanges($this->options['message']));
    $this->log()->notice('Site config was committed.');
    return $workflow;
  }

  protected function drushCex(Workflow $workflow = NULL) {
    if ($workflow) {
      $this->wait($workflow);
    }
    $this->prepareEnvironment($this->site->id . '.' . $this->env->id);
    $this->log()->notice('Exporting site configuration.');
    $this->executeCommand(['drush', 'cex', '-y']);
  }

  protected function setSftp() {
    if (in_array($this->env->id, ['test', 'live',])) {
      throw new TerminusException(
        'Connection mode cannot be set on the {env} environment',
        ['env' => $this->env->id]
      );
    }
    try {
      $this->log()->notice('Setting connection mode to SFTP');
      $workflow = $this->env->changeConnectionMode('sftp');
      // Terminus changes connection mode on the remote, but fails to
      // change the connection mode property.
      // @see https://github.com/pantheon-systems/terminus/issues/2122
      $this->env->set('connection_mode', 'sftp');
    }
    catch (TerminusException $e) {
      $message = $e->getMessage();
      if (strpos($message, 'sftp') !== FALSE) {
        $this->log()->notice($message);
        return;
      }
      throw $e;
    }

    // Sometimes we get a null workflow in response.
    if ($workflow) {
      $workflow = $this->processWorkflow($workflow);
      if ($workflow) {
        $this->log()->notice($workflow->getMessage());
      }
      return $workflow;
    }
  }

  protected function wait(Workflow $workflow) {
    $startWaiting = time();
    while (TRUE) {
      $workflowCreationTime = $workflow->get('created_at');
      $workflowDescription = $workflow->get('description');

      if (($workflowCreationTime > $startWaiting)) {
        $this->log()
          ->notice("Workflow '{current}' {status}.", [
            'current' => $workflowDescription,
            'status' => $workflow->getStatus(),
          ]);
        if ($workflow->isSuccessful()) {
          $this->log()->notice("Workflow succeeded");
          return;
        }
      }
      else {
        $this->log()
          ->notice("Waiting for '{current}'", ['current' => $workflowDescription]);
      }
      // Wait a bit, then spin some more
      sleep(1);

      if (time() - $startWaiting >= self::DEFAULT_WORKFLOW_TIMEOUT) {
        $this->log()
          ->warning("Waited '{max}' seconds, giving up waiting for workflow to finish", ['max' => self::DEFAULT_WORKFLOW_TIMEOUT]);
        break;
      }
    }
  }

}
