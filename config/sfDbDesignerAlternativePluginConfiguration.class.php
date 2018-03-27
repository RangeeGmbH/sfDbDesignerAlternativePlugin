<?php
/**
 * Configuration for sfDbDesignerAlternativePlugin
 * 
 * @author cschaefe
 *
 */
class sfDbDesignerAlternativePluginConfiguration extends sfPluginConfiguration
{

  /**
   * Connect sfDbDesignerAlternativePlugin to command.pre_command event
   * 
   * @see sfPluginConfiguration
   */
  public function configure()
  {
    if (class_exists('sfTaskExtraAddon', true))
    {
      $this->dispatcher->connect('command.pre_command', array($this, 'listenPreCommand'));
    }
  }

  /**
   * Listens for the 'command.pre_command' event.
   *
   * @param   sfEvent $event
   *
   * @return  boolean
   */
  public function listenPreCommand(sfEvent $event)
  {
    $task = $event->getSubject();

    if ($task instanceof sfPropelBuildModelTask)
    {
      $alternative = new sfDbDesignerAlternativeAddon($this->configuration, new sfAnsiColorFormatter());
      $alternative->setWrappedTask($task);
      $alternative->executeAddon();

      return false;
    }

    return false;
  }
}
