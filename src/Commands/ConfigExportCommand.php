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
   * @option string $remote-destination Remote destination directory relative to /tmp/ to sync files. Defaults to 'config-export'.
   * @option boolean $keep-remote Keep the remote export directory instead of deleting it afterwards.
   * @usage <site.env> Exports config from <site.env> to <destination>
   */

  public function configExportRemote($site_env, $options = ['destination' => '', 'remote-destination' => '', 'keep-remote' => FALSE]) {
    $this->prepareEnvironment($site_env);
    [$this->site, $this->env] = $this->getSiteEnv($site_env);
    $sftp = $this->env->sftpConnectionInfo();
    if (empty($remote_destination)) {
      $remote_destination = 'config-export-' . time();
    }
    // For some reason sshCommand doesn't work for this:
    $this->log()->notice('Verifying remote destination.');
    // "-mkdir" lets an existing --remote-destination directory be reused.
    $this->sftpBatch($sftp, ["-mkdir files/private/{$remote_destination}"]);
    try {
      $this->log()->notice('Exporting remote config.');
      $this->executeCommand(['drush', 'cex', '--destination=private://' . $remote_destination]);
      $destination = $options['destination'];
      $this->log()->notice('Verifying local destination.');
      if (empty($options['destination'])) {
        $destination = getcwd();
      }
      $destination = rtrim($destination, '/');
      $this->log()->notice('Rsyncing config from remote to local.');
      // Removing each file as it is copied is much faster than deleting
      // them one by one over SFTP afterwards.
      $this->rsync($site_env, ':files/private/' . $remote_destination . '/', $destination, TRUE, empty($options['keep-remote']));
    }
    finally {
      if (empty($options['keep-remote'])) {
        $this->log()->notice('Removing remote export directory.');
        // SFTP has no recursive delete. "-rm" clears any files left by a
        // failed export or copy. A cleanup failure must not hide an earlier
        // export error.
        try {
          $this->sftpBatch($sftp, [
            "-rm files/private/{$remote_destination}/*",
            "rmdir files/private/{$remote_destination}",
          ]);
        }
        catch (TerminusException $e) {
          $this->log()->warning('Could not remove the remote export directory: {message}', ['message' => $e->getMessage()]);
        }
      }
    }
    $this->log()->notice('Cleaning up file permissions.');
    passthru('chmod 644 ' . $destination . '/*.yml');
  }

  /**
   * Runs SFTP commands against the environment in batch mode.
   *
   * @param array $sftp
   *   Connection info, as returned by Environment::sftpConnectionInfo().
   * @param string[] $commands
   *   SFTP commands to run. Prefix a command with "-" to ignore its failure.
   *
   * @throws \Pantheon\Terminus\Exceptions\TerminusException
   *   If there is no connection info, SFTP cannot connect, or a command fails.
   */
  protected function sftpBatch(array $sftp, array $commands) {
    if (empty($sftp['username']) || empty($sftp['host'])) {
      throw new TerminusException(
        'No SFTP connection information is available for the {env} environment.',
        ['env' => $this->env->id]
      );
    }
    $command = sprintf(
      'sftp -b - -o Port=%s -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no %s',
      escapeshellarg($sftp['port'] ?? '2222'),
      escapeshellarg($sftp['username'] . '@' . $sftp['host'])
    );
    $process = proc_open($command, [0 => ['pipe', 'r']], $pipes);
    if (!is_resource($process)) {
      throw new TerminusException('Could not start `{command}`.', ['command' => $command]);
    }
    fwrite($pipes[0], implode("\n", $commands) . "\n");
    fclose($pipes[0]);
    $status = proc_close($process);
    if ($status !== 0) {
      throw new TerminusException('Command `{command}` failed with exit code {status}', ['command' => $command, 'status' => $status]);
    }
  }

  /**
   * Call rsync to or from the specified site.
   *
   * @param string $site_env_id Remote site
   * @param string $src Source path to copy from. Start with ":" for remote.
   * @param string $dest Destination path to copy to. Start with ":" for remote.
   * @param boolean $ignoreIfNotExists Silently fail and do not return error if remote source does not exist.
   * @param boolean $removeSourceFiles Delete each source file once it has been copied.
   */
  public function rsync($site_env_id, $src, $dest, $ignoreIfNotExists = true, $removeSourceFiles = false)
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
    $remove = $removeSourceFiles ? ' --remove-source-files' : '';
    $command = "rsync -rlIvz --ipv4 --exclude=.git{$remove} -e 'ssh -p 2222 -o UserKnownHostsFile=/dev/null -o StrictHostKeyChecking=no' $src $dest";
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

  protected function setGit(?Workflow $workflow = NULL) {
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

  protected function drushCex(?Workflow $workflow = NULL) {
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
