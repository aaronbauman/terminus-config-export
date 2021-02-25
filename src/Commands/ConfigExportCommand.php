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
    [$this->site, $this->env] = $this->getSiteEnv($site_env);
    $workflow = $this->setSftp();
    $this->drushCex($workflow);
    $workflow = $this->envCommit();
    $this->setGit($workflow);
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
