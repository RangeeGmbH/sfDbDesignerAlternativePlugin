<?php
/**
 * Task Addon converting dbdesigner files to symfony alternative schema
 *
 * @package     sfDbDesignerAlternativePlugin
 * @subpackage  task
 * @author      Christoph Schaefer <christoph.schaefer <<at>> sinntax.de>
 * @version     SVN: $Id$
 */
class sfDbDesignerAlternativeAddon extends sfTaskExtraAddon
{
  protected $count = 0;

  /**
   * @see sfTaskExtraAddon
   */
  public function executeAddon($arguments = array(), $options = array())
  {
    if (!class_exists('XSLTProcessor'))
    {
      throw new Exception(sprintf("PHP XSLT extension not found!"));
    }

    $this->logSection('dbdesigner', 'Converting files to alternative schema.');

    $configs = $this->getConfiguration();

    foreach ($configs as $dir => $config)
    {
      $this->logSection('scan', sprintf('scanning "%s"', $dir));
       
      // get dbdesigner save files in plugin
      foreach (sfFinder::type('file')->name('*.xml')->in($dir) as $dbdesignerFile)
      {
        // name of save file
        $name = substr_replace(basename($dbdesignerFile), '', -4);

        // merge file  config
        $conf = array_merge($config['all'], isset($config[$name]) && is_array($config[$name]) ? $config[$name] : array());

        // start processing
        $this->logSection('file', sprintf('processing "%s"', basename($dbdesignerFile)));

        $dom = $this->loadDomDocumentFromFile($dbdesignerFile);

        foreach (array('dbd2propel.xsl', 'propel_i18n.xsl') as $xslt)
        {
          $proc = new XSLTProcessor();
          $proc->importStyleSheet($this->loadDomDocumentFromFile(realpath(dirname(__FILE__). '/../').'/vendor/xsl/'.$xslt));
          $dom = $proc->transformToDoc($dom);
        }

        $schema = new sfPropelDatabaseSchema();
        // sfPropelDatabaseSchema does not allow loading of XML strings
        // using RFC 2397 stream wrapper; requires PHP 5.20 <=
        $schema->loadXML('data:text/plain,'.$dom->saveXML());
        unset($dom);

        $yaml = $schema->convertOldToNewYaml($schema->asArray());
        unset($schema);

        $this->updateYaml($yaml, $conf);

        if (!file_exists($conf['save_dir']) || !is_dir($conf['save_dir']))
        {
          throw new sfException(sprintf('Unknown "save_dir"! Directory "%s" does not exist!', $conf['save_dir']));
        }

        $file = sprintf('%s/%s.yml', $conf['save_dir'], isset($conf['filename']) && !empty($conf['filename']) ? $conf['filename'] : $name);
        file_put_contents($file, sfYaml::dump($yaml, 4));

        $this->logSection('schema', 'wrote '.str_replace($this->configuration->getRootDir().'/', '', $file));
        ++$this->count;
      }
    }

    $this->logSection('dbdesigner', sprintf('Converted %d files.', $this->count));
  }

  /**
   * loads dbdesigner.yml, transform to dir keys, sets defaults
   *
   * @return array
   */
  protected function getConfiguration()
  {
    $configs = $this->loadConfigurations();

    $plugins = $configs['plugins'];
    unset($configs['plugins']);

    // merge project and set path
    $configs = $this->mergeConfig($configs);
    foreach($configs as $dir => $files)
    {
      $configs[$files['all']['base_dir'].'/'.$dir] = $files;
      unset($configs[$dir]);
    }

    // merge plugins and set path
    foreach ($this->mergeConfig($plugins) as $plugin => $dirs)
    {
      foreach ($this->mergeConfig($dirs) as $dir => $config)
      {
        $configs[sprintf('%s/%s', $config['all']['base_dir'], trim($dir, '/'))] = is_array($config) ? $config : array();
      }
    }

    // replace constants
    foreach ($configs as $dir => &$files)
    {
      foreach ($files as $file => &$config)
      {
        foreach ($config as $key => &$value)
        {
          if (isset($config['plugin_name']))
          {
            $config[$key] = str_replace('%PLUGIN_NAME%', $config['plugin_name'], $value);
          }

          if (isset($config['base_dir']))
          {
            $config[$key] = str_replace('%BASE_DIR%', $config['base_dir'], $value);
          }
          
          $config[$key] = sfToolkit::replaceConstants($value);
        }
      }
    }

    return $configs;
  }

  /**
   * modifies an alternative schema yaml array
   *
   * @param $yaml
   * @param $config
   * @return void
   */
  protected function updateYaml(array &$yaml, array $config)
  {
    // quick hack, xslt knows nothing about plugins
    if (isset($config['package']))
    {
      $yaml['package'] = $config['package'];
    }

    if (!isset($yaml['classes']) || !is_array($yaml['classes']))
    {
      // schema without classes; nothing to do
      return;
    }

    if (isset($config['class_prefix']) && !empty($config['class_prefix']))
    {
      foreach(array_keys($yaml['classes']) as $class)
      {
        $yaml['classes'][$config['class_prefix'].$class] = $yaml['classes'][$class];
        unset($yaml['classes'][$class]);
      }
    }

    $classByTable = array();
    foreach($yaml['classes'] as $class => $values)
    {
      $classByTable[$values['tableName']] = $class;
    }

    // replace foreignTable definitions by class definitions
    foreach($yaml['classes'] as $class => &$values)
    {
      // replace single foreignKeys in column definitions
      if (isset($values['columns']))
      {
        foreach ($values['columns'] as $column => &$options)
        {
          if (isset($options['foreignTable']))
          {
            $options['foreignClass'] = $classByTable[$options['foreignTable']];
            unset($options['foreignTable']);
          }
        }
      }

      // replace in composite foreign-keys definitions
      if (isset($values['foreignKeys']))
      {
        foreach ($values['foreignKeys'] as &$foreignKey)
        {
          if (isset($foreignKey['foreignTable']))
          {
            $foreignKey['foreignClass'] = $classByTable[$foreignKey['foreignTable']];
            unset($foreignKey['foreignTable']);
          }
        }
      }
    }

    unset($classByTable);
    if (isset($config['table_prefix']) && !empty($config['table_prefix']))
    {
      foreach($yaml['classes'] as $class => &$values)
      {
        if (isset($values['tableName']))
        {
          $values['tableName'] = $config['table_prefix'].$values['tableName'];
        }
        if (isset($values['i18nTable']))
        {
          $values['i18nTable'] = $config['table_prefix'].$values['i18nTable'];
        }
      }
    }
  }

  /**
   * Merges 'all' key to all elements on the same level
   *
   * @param $array
   * @return array
   */
  protected function mergeConfig(array $array)
  {
    if (isset($array['all']))
    {
      $all = is_array($array['all']) ? $array['all'] : array();
      unset($array['all']);
      foreach ($array as $key => $value)
      {
        $array[$key] = sfToolkit::arrayDeepMerge($all, is_array($value) ? $value : array());
      }
    }

    return $array;
  }

  /**
   * loads all dbdesigner.yml files
   *
   * @return array
   */
  protected function loadConfigurations()
  {
    $finder = sfFinder::type('file')->name('dbdesigner.yml');

    $configs = array('plugins' => array());
    foreach ($finder->in($this->configuration->getPluginSubPaths('/config')) as $configFile)
    {
      $pluginName = basename(realpath(dirname($configFile).'/..'));
      $configs['plugins'][$pluginName] = $this->loadConfiguration($configFile);
      // plugins need name
      $configs['plugins'][$pluginName]['all']['all']['plugin_name'] = $pluginName;
    }

    $configFile = $finder->in(sfConfig::get('sf_config_dir'));

    if (isset($configFile[0]))
    {
      $configs = sfToolkit::arrayDeepMerge($configs, $this->loadConfiguration($configFile[0]));
    }

    $configs = sfToolkit::arrayDeepMerge(sfSimpleYamlConfigHandler::parseYaml(realpath(dirname(__FILE__). '/../../').'/config/dbdesigner_defaults.yml'), $configs);

    return $configs;
  }

  /**
   * Loads a single config file and sets the base dir
   *
   * @param $configFile
   * @return array
   */
  protected function loadConfiguration($configFile)
  {
    $yaml = sfSimpleYamlConfigHandler::parseYaml($configFile);
    $yaml['all']['all']['base_dir'] = realpath(dirname($configFile).'/..');

    return $yaml;
  }
  
  /**
   * loads DomDocument from file
   *
   * @param $file
   * @return DomDocument
   */
  protected function loadDomDocumentFromFile($file)
  {
    $dom = new DomDocument();

    try
    {
      $dom->load($file);
    }
    catch (Exception $e)
    {
      throw new sfException(sprintf('Cannot load %s XML! %s', $file, $e->getMessage()));
    }

    $dom->formatOutput = false;

    return $dom;
  }
}
