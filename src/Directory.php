<?php

namespace EvolutionCMS\Directory;

use DocumentManager;
use EvolutionCMS\Models\{SiteContent, SiteTmplvar};
use EvolutionCMS\Directory\Filters;

class Directory
{
    private $configs;

    public function getConfigs()
    {
        if ($this->configs !== null) {
            return $this->configs;
        }

        $configs = [];

        foreach (glob(EVO_CORE_PATH . 'custom/directory/*.php') as $entry) {
            $config = include($entry);

            if (is_array($config) && isset($config['ids'])) {
                foreach ($config['ids'] as $id) {
                    $configs[$id] = $config;
                }
            }
        }

        return $this->configs = $configs;
    }

    public function getConfig($id)
    {
        $configs = $this->getConfigs();

        if (isset($configs[$id])) {
            $config = $configs[$id];
            $default = $this->getDefaultConfig();
            $config['columns'] = array_merge($default['columns'], $config['columns'] ?? []);
            $config = array_merge($default, $config);
            $config['lang'] = array_merge(__('directory::messages'), $config['lang']);

            $sort = 0;
            $config['columns'] = array_map(function($column) use (&$sort) {
                if (!isset($column['order'])) {
                    $column['order'] = $sort++;
                }
                return $column;
            }, $config['columns']);

            uasort($config['columns'], function($a, $b) {
                return $a['sort'] - $b['sort'];
            });

            $config['id'] = $id;

            return $config;
        }

        return null;
    }

    public function getResources(SiteContent $parent, array $config)
    {
        $names = array_keys($config['columns']);
        $tvs = $this->getTmplvarsValues($names);

        $items = $parent->children()
            ->withTVs($names)
            ->when(isset($config['query']), $config['query']);

        $items = (new Filters())->injectFilters($items, array_keys($config['columns']));

        $items = $items
            ->orderBy('isfolder', 'desc')
            ->orderBy('menuindex')
            ->paginate(20)
            ->appends(request()->query())
            ->through(function($item) use ($config, $tvs) {
                if (isset($config['prepare'])) {
                    $item = call_user_func($config['prepare'], $item, $config);
                }

                if (!($item instanceof SiteContent)) {
                    return false;
                }

                foreach ($tvs as $name => $options) {
                    if (isset($item->{$name}) && is_scalar($item->{$name})) {
                        $result = [];
                        $values = array_map('trim', explode('||', $item->{$name}));

                        foreach ($values as $value) {
                            if (isset($options['values'][$value])) {
                                $value = $options['values'][$value];
                            }

                            $result[] = $value;
                        }

                        $item->{$name} = implode(', ', $result);
                    }
                }

                return $item;
            });

        return $items;
    }


    public function actionPublish($resources)
    {
        $resources->update(['published' => 1]);
    }

    public function actionUnpublish($resources)
    {
        $resources->update(['published' => 0]);
    }

    public function actionDelete($resources)
    {
        $resources->update(['deleted' => 1]);
    }

    public function actionRestore($resources)
    {
        $resources->update(['deleted' => 0]);
    }

    public function actionDuplicate($resources)
    {
        $resources->each(function($resource) {
            DocumentManager::duplicate([
                'id' => $resource->id,
            ]);
        });
    }

    private function getTmplvarsValues(array $names = [])
    {
        $result = [];

        foreach ($names as $name) {
            $row = SiteTmplvar::where('name', $name)->first();

            if (!empty($row->elements)) {
                $values   = [];
                $elements = ParseIntputOptions(ProcessTVCommand($row->elements, '', '', 'tvform', $tv = []));

                if (!empty($elements)) {
                    foreach ($elements as $element) {
                        list($val, $key) = is_array($element) ? $element : explode('==', $element);

                        if (strlen($val) == 0) {
                            $val = $key;
                        }

                        if (strlen($key) == 0) {
                            $key = $val;
                        }

                        $values[$key] = $val;
                    }
                }

                if (!empty($values)) {
                    $result[$name] = [
                        'values' => $values,
                    ];

                    if (in_array($row->type, ['checkbox', 'listbox-multiple'])) {
                        $result[$name]['multiple'] = true;
                    }
                }
            }
        }

        return $result;
    }

    public function getCrumbs($res)
    {
        $ancestors = null;
        if(!empty($res->id)) {
            $arr = array_reverse(array_keys(evo()->getParentIds($res->id)));
            if(!empty($arr)) {
                $ancestors = SiteContent::whereIn('id', $arr)
                    ->orderByRaw("FIND_IN_SET(id, '" . implode(',', $arr) . "') ")
                    ->get();
            }
        }
        return $ancestors;
    }

    private function getDefaultConfig()
    {
        return [
            'show_actions' => true,

            'actions' => [
                'publish',
                'unpublish',
                'delete',
                'restore',
                'duplicate',
            ],

            'columns' => [
                'pagetitle' => [
                    'caption' => __('directory::messages.pagetitle'),
                    'sort' => 0,
                    'renderer' => function($value, $row, $config) {
                        if ($row->isfolder) {
                            return '
                                <i class="fa fa-folder"></i>
                                <a href="' . route('directory::show', ['document' => $config['id'], 'folder' => $row->id]) . '">' . $row->pagetitle . '</a>
                            ';
                        } else {
                            return '
                                <i class="fa fa-file-o"></i>
                                <a href="index.php?a=27&id=' . $row->id . '" title="' . $config['lang']['edit_document'] . '" target="main">' . $row->pagetitle . '</a>
                            ';
                        }
                    }
                ],
            ],

            'limits' => [
                10, 25, 50, 100
            ],

            'default_limit' => 25,
        ];
    }
}
