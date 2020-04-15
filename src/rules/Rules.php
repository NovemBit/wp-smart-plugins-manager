<?php


namespace NovemBit\wp\plugins\spm\rules;


use diazoxide\helpers\Environment;
use diazoxide\helpers\HTML;
use diazoxide\helpers\URL;
use diazoxide\helpers\Variables;
use diazoxide\wp\lib\option\v2\Option;
use NovemBit\wp\plugins\spm\Bootstrap;

class Rules
{

    public const TYPE_REQUEST = 'request';
    public const TYPE_GET = 'get';
    public const TYPE_POST = 'post';
    public const TYPE_COOKIE = 'cookie';
    public const TYPE_SERVER = 'server';
    public const TYPE_HOOK = 'hook';
    public const TYPE_FUNCTION = 'function';

    public const LOGIC_AND = 'and';
    public const LOGIC_OR = 'or';
    public const LOGIC_NOT = 'not';
    /**
     * @var Bootstrap
     * */
    public $parent;

    /**
     * @var $patterns
     * */
    public $patterns;

    /**
     * @var array
     * */
    public $tabs = [];

    /**
     * Patterns constructor.
     * @param Bootstrap $parent
     */
    public function __construct(Bootstrap $parent)
    {
        $this->parent = $parent;
        $this->patterns = new Patterns($this);

        if (is_admin()) {
            $this->adminInit();
        }
    }

    /**
     * @return void
     * @uses adminMenu
     */
    public function adminInit(): void
    {
        add_action('admin_menu', [$this, 'adminMenu']);

        $this->tabs['default'] = ['label' => 'Rules', 'content' => [$this, 'defaultTabContent']];
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->parent->getName() . '-rules';
    }

    /**
     * @return void
     * @uses adminContent
     */
    public function adminMenu(): void
    {
        add_submenu_page(
            $this->parent->getName(),
            __('Rules', 'novembit-spm'),
            __('Rules', 'novembit-spm'),
            'manage_options',
            $this->getName(),
            [$this, 'adminContent']
        );
    }

    /**
     * Admin Content
     * @return void
     */
    public function adminContent(): void
    {
        $tabs = [];
        $current_url = admin_url('admin.php?page=' . $this->getName());
        $active = Environment::get('action') ?? 'default';
        $active_tab = $this->tabs[$active];

        foreach ($this->tabs as $tab => $params) {
            $url = URL::addQueryVars($current_url, 'action', $tab);
            $active_class = $active === $tab ? ' nav-tab-active' : '';
            $tabs[] = [
                'a',
                $params['label'] ?? $tab,
                ['class' => 'nav-tab' . $active_class, 'href' => $url]
            ];
        }
        echo HTML::tag('nav', $tabs, ['class' => 'nav-tab-wrapper']);

        $content = $active_tab['content'] ?? null;

        if (is_callable($content)) {
            $content();
            return;
        }

        if (is_string($content)) {
            echo $content;
        }
    }

    public function defaultTabContent(): void
    {
        ?>
        <h1>Rules</h1>
        <?php
    }

    public static function getRulesSettings(): array
    {
        return [
            'rule' => self::getRuleSetting(),
            'logic' => self::getLogicSetting()
        ];
    }

    public static function getLogicSetting(): array
    {
        return [
            'type' => Option::TYPE_TEXT,
            'label' => 'Logic',
            'values' => [
                self::LOGIC_AND => 'And',
                self::LOGIC_OR => 'Or',
                self::LOGIC_NOT => 'Not',
            ]
        ];
    }

    /**
     * @return array
     */
    public static function getRuleSetting(): array
    {
        return [
            'type' => Option::TYPE_GROUP,
            'method' => Option::METHOD_MULTIPLE,
            'label' => 'Single Rule',
            'main_params' => ['style' => 'grid-template-columns: repeat(3, 1fr);display:grid'],
            'template' => [
                'type' => [
                    'type' => Option::TYPE_TEXT,
                    'values' => [
                        self::TYPE_REQUEST => 'Request',
                        self::TYPE_GET => 'Get',
                        self::TYPE_POST => 'Post',
                        self::TYPE_COOKIE => 'Cookie',
                        self::TYPE_SERVER => 'Server',
                        self::TYPE_HOOK => 'Hook',
                        self::TYPE_FUNCTION => 'Function'
                    ]
                ],
                'key' => [
                    'type' => Option::TYPE_TEXT,
                    'label' => 'Key',
                ],
                'compare' => [
                    'type' => Option::TYPE_TEXT,
                    'label' => 'Compare operator',
                    'default' => 'equal',
                    'values' => [
                        Variables::COMPARE_EQUAL => 'Equal ( == )',
                        Variables::COMPARE_NOT_EQUAL => 'Not equal ( <> )',
                        Variables::COMPARE_IDENTICAL => 'Identical ( === )',
                        Variables::COMPARE_NOT_IDENTICAL => 'Not Identical ( !== )',
                        Variables::COMPARE_GREATER_THAN => 'Greater than ( > )',
                        Variables::COMPARE_GREATER_THAN_OR_EQUAL => 'Greater than or equal ( >= )',
                        Variables::COMPARE_LESS_THAN => 'Less than ( < )',
                        Variables::COMPARE_LESS_THAN_OR_EQUAL => 'Less than or equal ( <= )',
                        Variables::COMPARE_CONTAINS => 'Contains ( %word% )',
                        Variables::COMPARE_REGEXP => 'Regular expression ( /^(man|woman)$/ )',
                        Variables::COMPARE_STARTS_WITH => 'Starts with',
                        Variables::COMPARE_ENDS_WITH => 'Ends with',
                    ]
                ],
                'value' => [
                    'type' => Option::TYPE_TEXT,
                    'label' => 'Value',
                ],
                'params' => [
                    'label' => 'Additional params ( when needed )',
                    'method' => Option::METHOD_MULTIPLE,
                    'type' => Option::TYPE_TEXT
                ],
                'logic' => self::getLogicSetting()
            ],
        ];
    }

    public function checkRules(array $rules): bool
    {
        $status = null;

        foreach ($rules as $_rules) {
            $logic = $_rules['logic'] ?? 'and';

            if (isset($_rules['rule'])) {
                $assertion = $this->checkRules(array_values($_rules['rule']));
            } else {
                $type = $_rules['type'] ?? null;
                $key = $_rules['key'] ?? null;
                $value = $_rules['value'] ?? null;
                $compare = $_rules['compare'] ?? null;

                if (!$type || !$key) {
                    continue;
                }

                if (in_array($type, ['request', 'get', 'post', 'server', 'cookie'])) {
                    $_value = call_user_func([Environment::class, $type], $key);
                } elseif ($type === 'hook') {
                    $_value = apply_filters($key);
                } elseif ($type === 'function') {
                    $params = $_rules['params'] ?? [];
                    $_value = $key(...$params);
                } else {
                    continue;
                }
                $assertion = Variables::compare($compare, $_value, $value);
            }

            if ($logic === 'and') {
                $status = ($status ?? true) && $assertion;
            }
            if ($logic === 'or') {
                $status = ($status ?? true) || $assertion;
            }
            if ($logic === 'not') {
                $status = ($status ?? true) && !$assertion;
            }
        }

        return $status ?? false;
    }

}