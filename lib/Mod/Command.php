<?php

namespace Mod;

/**
 * Class Command
 *
 * Command parser
 *
 * @package Pac
 */
class Command
{
    /**
     * @var $this
     */
    protected static $instance;

    /**
     * @var array
     */
    public $result = [];

    /**
     * @var array Default options
     */
    protected static $defaultConfig = array(
        'auto' => true,
        'options' => []
    );

    protected $config;

    /**
     * Instance
     *
     * @return Command
     */
    public static function instance()
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Set Config
     *
     * @param array $config
     * @return $this
     */
    public static function config(array $config = null)
    {
        if ($config) {
            return self::instance()->setConfig($config);
        } else {
            return self::instance()->getConfig($config);
        }
    }

    /**
     * Parse the args array or string
     *
     * @param string|array $args
     * @param array $config
     * @return array
     */
    public static function parse($args, array $config = [])
    {
        return self::instance()
            ->setConfig($config)
            ->parseCommand($args)
            ->getResult();
    }

    /**
     * @param array|null $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge(self::$defaultConfig, $config);
    }

    /**
     * Add option
     *
     * @param $name
     * @param $option
     *  name        Name for option
     *  alias       Alias name
     *  type        Option type: string, bool
     * @return $this
     */
    public function addOption($name, $option)
    {
        $this->config['options'][] = array_merge([
            'name' => $name,
            'type' => null,
            'alias' => null,
            'title' => null
        ], $option);
        return $this;
    }

    /**
     * Set config
     *
     * @param array $config
     * @return $this
     */
    public function setConfig(array $config)
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    /**
     * Get config
     *
     * @return $this
     */
    public function getConfig()
    {
        return $this->config;
    }

    /**
     * Get config
     *
     * @return array
     */
    public function getResult()
    {
        return $this->result;
    }

    /**
     * Set command
     *
     * @param string|array $args
     * @return $this
     */
    public function setCommand($args)
    {
        if (!is_array($args)) {
            $args = explode(" ", $args);
        }
        $this->config['program'] = array_shift($args);
        $this->config['args'] = $args;
        return $this;
    }

    /**
     * Parse command
     *
     * @param string|array $args
     * @return $this
     */
    public function parseCommand($args = null)
    {
        if ($args) $this->setCommand($args);
        $this->result = self::process($this->config);
        return $this;
    }

    /**
     * Parse the args array or string
     *
     * @param $config
     * @return array program     Program name
     *      program     Program name
     *      options     Options with key
     *      commands    Commands
     */
    public static function process(array $config = null)
    {
        if (!$config) $config = self::$defaultConfig;

        $optionConfigs = [];
        if (!empty($config['options'])) {
            foreach ($config['options'] as $optionKey => $optionConfig) {
                $optionConfigs[$optionConfig['name']] = $optionConfig;
                if (isset($optionConfig['alias'])) {
                    $optionConfigs[$optionConfig['alias']] = $optionConfig;
                }
            }
        }

        $args = $config['args'];
        $program = $config['program'];
        $commands = [];
        $options = [];
        $unknowns = [];
        $invalids = [];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            $nextArg = isset($args[$i + 1]) ? $args[$i + 1] : null;
            $process = false;
            $key = null;
            $value = null;

            if (static::isLongOption($arg)) {
                $argArr = explode('=', substr($arg, 2));
                $key = $argArr[0];
                $value = isset($argArr[1]) ? $argArr[1] : null;
                $process = true;
            } else if (static::isShortOption($arg)) {
                $key = $arg[1];
                $value = substr($arg, 2);
                $process = true;
            }

            if ($process) {
                $optionConfig = isset($optionConfigs[$key]) ? $optionConfigs[$key] : [
                    'name' => $key,
                    'type' => null
                ];

                $name = $optionConfig['name'];
                $type = $optionConfig['type'];

                if ($type === 'string') {
                    if (!$value && $nextArg) $value = $nextArg;
                    $options[$name] = $value;
                    continue;
                } else if ($type === 'number') {
                    if (!$value && $nextArg) $value = $nextArg;
                    if (!is_numeric($value)) $invalids[$name] = $value;
                    else $options[$name] = (float)$value;
                    continue;
                } else if ($type === 'bool') {
                    if ($value !== 'true' && $value !== 'false') {
                        $invalids[$name] = $value;
                        continue;
                    } else if ($value === 'false') $options[$name] = false;
                    else $options[$name] = true;
                    continue;
                } else {
                    /**
                     * Auto detect
                     *
                     * program -a       =        a: true
                     * program -ab      =        a: b
                     * program -a b     =        a: b
                     * program -atrue   =        a: true
                     * program -a true  =        a: true
                     * program -a false  =       a: false
                     */
                    if ($value) {
                        $options[$name] = static::autoValue($value);
                    } else if ($nextArg && static::isNotOption($nextArg)) {
                        $options[$name] = static::autoValue($nextArg);
                        ++$i;
                    } else {
                        $options[$name] = true;
                    }

                    if (!isset($optionConfigs[$key]) && !$config['auto']) {
                        $unknowns[$name] = $options[$name];
                        unset($options[$name]);
                    }
                }
            } else {
                $commands[] = $arg;
            }
        }

        return [
            'program' => $program,
            'options' => $options,
            'commands' => $commands,
            'unknowns' => $unknowns,
            'invalids' => $invalids
        ];
    }

    /**
     * Get help
     *
     * @param $commands
     */
    public function outputHelp($commands)
    {
        echo self::help($commands, $this->config);
    }


    /**
     * Output help block
     *
     * @param array $commands
     * @param array $config
     * @return string
     */
    public static function help(array $commands, array $config = null)
    {
        echo self::buildHelp($commands, $config);
    }

    /**
     * Build help block
     *
     * @param array $commands
     * @param array $config
     * @return string
     */
    public static function buildHelp(array $commands, array $config = null)
    {
        if (!$config) $config = self::$defaultConfig;

        $text = PHP_EOL . Command::text('USAGE', 'bold') . PHP_EOL . '  '. (!empty($config['usage']) ? $config['usage'] : ($config['program'] . " <option> [command]")) . PHP_EOL;

        if (!$commands) {
            $text .= PHP_EOL . Command::text('COMMANDS', 'bold') . PHP_EOL;
            foreach ($commands as $command => $title) {
                $text .= '  ' . str_pad($command, 20, " ", STR_PAD_RIGHT) . $title . PHP_EOL;
            }
        }

        if (!empty($config['options'])) {
            $text .= PHP_EOL . Command::text('OPTIONS', 'bold') . PHP_EOL;
            foreach ($config['options'] as $option) {
                $text .= '  --' . str_pad($option['name'] . (!empty($option['alias']) ? '|-' . $option['alias'] : ''), 18, " ", STR_PAD_RIGHT) . (!empty($option['title']) ? $option['title'] : $option['type']) . PHP_EOL;
            }
        }
        return $text . PHP_EOL;
    }

    /**
     * Convert to safe value
     *
     * @param string $value
     * @return bool
     */
    protected static function autoValue($value)
    {
        if ($value === 'true' || $value === '1') return true;
        else if ($value === 'false' || $value === '0') return false;
        else if (is_numeric($value)) return $value + 0;
        return $value;
    }

    /**
     * Check if is not a option
     *
     * @param string $arg
     * @return bool
     */
    protected static function isNotOption($arg)
    {
        return $arg[0] !== '-';
    }

    /**
     * Check if is long option
     *
     * @param string $arg
     * @return bool
     */
    protected static function isLongOption($arg)
    {
        return $arg[0] === '-' && $arg[1] === '-';
    }

    /**
     * Check if is short option
     *
     * @param string $arg
     * @return bool
     */
    protected static function isShortOption($arg)
    {
        return $arg[0] === '-' && $arg[1] !== '-';
    }

    /**
     * Check has option value
     *
     * @param string $arg
     * @return bool
     */
    protected static function hasOptionValue($arg)
    {
        return strpos($arg, '=') !== false;
    }
}
