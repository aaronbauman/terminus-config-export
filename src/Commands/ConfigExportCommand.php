<?php

namespace Pantheon\TerminusConfigExport\Commands;

use Pantheon\Terminus\Commands\Remote\SSHBaseCommand;
use Pantheon\Terminus\Commands\WorkflowProcessingTrait;
use Pantheon\Terminus\Exceptions\TerminusException;
use Pantheon\Terminus\Exceptions\TerminusProcessException;
use Pantheon\Terminus\Models\Workflow;
use Pantheon\Terminus\Site\SiteAwareInterface;
use Pantheon\Terminus\Site\SiteAwareTrait;

/**
 * Class UnfreezeCommand
 *
 * @package KevCooper\TerminusFreeze\Commands
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
   * @option string $message Commit message
   * @usage <site.env> Exports and commits config to <site.env>.
   */
  public function configExport($site_env, $options = ['message' => 'Config export']) {
    $this->options = $options;
    list($this->site, $this->env) = $this->getSiteEnv($site_env);
    $workflow = $this->setSftp();
    $this->drushCex($workflow);
    $workflow = $this->envCommit(time());
    $this->setGit($workflow);
  }

  protected function setGit(Workflow $workflow) {
    if ($this->env->hasUncommittedChanges()) {
      throw new TerminusException('Failed to commit changes.');
    }

    $this->wait($workflow);
    try {
      $this->log()->notice('Changing connection mode back to git.');
      $workflow = $this->env->changeConnectionMode('git');
    }
    catch (TerminusException $e) {
      $message = $e->getMessage();
      if (strpos($message, 'sftp') !== FALSE) {
        $this->log()->notice($message);
        return;
      }
      throw $e;
    }
    $this->processWorkflow($workflow);
    $this->log()->notice($workflow->getMessage());
  }

  protected function envCommit($time) {
    $change_count = count((array)$this->env->diffstat());
    if ($change_count === 0) {
      throw new TerminusException('There is no code to commit.');
    }
    $this->waitForWorkflow($time);
    $this->log()->notice('Committing site configuration to version control.');
    $workflow = $this->processWorkflow($this->env->commitChanges($this->options['message']));
    $this->log()->notice('Your code was committed.');
    return $workflow;
  }

  protected function drushCex(Workflow $workflow = NULL) {
    if ($workflow) {
      $this->wait($workflow);
    }
    $this->prepareEnvironment($this->site->id . '.' . $this->env->id);
    $this->setProgressAllowed(true);
    $this->log()->notice('Exporting site configuration.');
    $this->executeCommand(['drush', 'cex', '-y']);
  }

  protected function setSftp() {
    if (in_array($this->env->id, ['test', 'live',])) {
      throw new TerminusException(
        'Connection mode cannot be set on the {env} environment',
        ['env' => $this->id,]
      );
    }
    try {
      $this->log()->notice('Setting connection mode to SFTP');
      $workflow = $this->env->changeConnectionMode('sftp');
    }
    catch (TerminusException $e) {
      $message = $e->getMessage();
      if (strpos($message, 'sftp') !== FALSE) {
        $this->log()->notice($message);
        return;
      }
      throw $e;
    }
    $workflow = $this->processWorkflow($workflow);
    $this->log()->notice($workflow->getMessage());
    return $workflow;
  }

  protected function wait(Workflow $workflow) {
    $startWaiting = time();
    while(true) {
      $workflowCreationTime = $workflow->get('created_at');
      $workflowDescription = $workflow->get('description');

      if (($workflowCreationTime > $startWaiting)) {
        $this->log()->notice("Workflow '{current}' {status}.", ['current' => $workflowDescription, 'status' => $workflow->getStatus(), ]);
        if ($workflow->isSuccessful()) {
          $this->log()->notice("Workflow succeeded");
          return;
        }
      }
      else {
        $this->log()->notice("Waiting for '{current}'", ['current' => $workflowDescription]);
      }
      // Wait a bit, then spin some more
      sleep(1);

      if (time() - $startWaiting >= self::DEFAULT_WORKFLOW_TIMEOUT) {
        $this->log()->warning("Waited '{max}' seconds, giving up waiting for workflow to finish", ['max' => self::DEFAULT_WORKFLOW_TIMEOUT]);
        break;
      }
    }
  }

  /**
   * Wait for a workflow to complete.
   *
   * @param int $startTime Ignore any workflows that started before the start time.
   * @param string $workflow The workflow message to wait for.
   */
  protected function waitForWorkflow($startTime, $expectedWorkflowDescription = '', $maxWaitInSeconds = null)
  {
    if (empty($expectedWorkflowDescription)) {
      $expectedWorkflowDescription = "Sync code on " . $this->env->id;
    }

    if (null === $maxWaitInSeconds) {
      $maxWaitInSecondsEnv = getenv('TERMINUS_BUILD_TOOLS_WORKFLOW_TIMEOUT');
      $maxWaitInSeconds = $maxWaitInSecondsEnv ? $maxWaitInSecondsEnv : self::DEFAULT_WORKFLOW_TIMEOUT;
    }

    $startWaiting = time();
    while(true) {
      $workflow = $this->getLatestWorkflow();
      $workflowCreationTime = $workflow->get('created_at');
      $workflowDescription = $workflow->get('description');

      if (($workflowCreationTime > $startTime) && ($expectedWorkflowDescription == $workflowDescription)) {
        $this->log()->notice("Workflow '{current}' {status}.", ['current' => $workflowDescription, 'status' => $workflow->getStatus(), ]);
        if ($workflow->isSuccessful()) {
          $this->log()->notice("Workflow succeeded");
          return;
        }
      }
      else {
        $this->log()->notice("Current workflow is '{current}'; waiting for '{expected}'", ['current' => $workflowDescription, 'expected' => $expectedWorkflowDescription]);
      }
      // Wait a bit, then spin some more
      sleep(5);

      if (time() - $startWaiting >= $maxWaitInSeconds) {
        $this->log()->warning("Waited '{max}' seconds, giving up waiting for workflow to finish", ['max' => $maxWaitInSeconds]);
        break;
      }
    }
  }

  /**
   * Fetch the info about the currently-executing (or most recently completed)
   * workflow operation.
   */
  protected function getLatestWorkflow()
  {
    $workflows = $this->site->getWorkflows()->fetch(['paged' => false,])->all();
    $workflow = array_shift($workflows);
    $workflow->fetchWithLogs();
    return $workflow;
  }

  /**
   * Return the first item of the $command_args that is not an option.
   *
   * @param array $command_args
   * @return string
   */
  private function firstArguments($command_args)
  {
    $result = '';
    while (!empty($command_args)) {
      $first = array_shift($command_args);
      if (strlen($first) && $first[0] == '-') {
        return $result;
      }
      $result .= " $first";
    }
    return $result;
  }

}
