<?php

namespace Bolt\Extension\Bolt\Labels;

use Bolt\BaseExtension;
use Bolt\Library as Lib;
use Symfony\Component\Filesystem\Exception\IOException;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\Request;

/**
 * Labels Extension for Bolt
 *
 * @author Bob den Otter <bob@twokings.nl>
 */
class Extension extends BaseExtension
{
    protected $labels;

    public function getName()
    {
        return 'Labels';
    }

    public function initialize()
    {
        $this->app->before(array($this, 'before'));

        // Twig functions
        $this->addTwigFunction('l', 'twigL');
        $this->addTwigFunction('setlanguage', 'twigSetLanguage');
        $this->addTwigFunction('setLanguage', 'twigSetLanguage'); # Deprecated! This was the old twig function.

        $root = $this->app['resources']->getUrl('bolt');

        // Admin menu
        $this->addMenuOption('Label translations', $root . 'labels', 'fa:flag');

        // Routess
        $this->app->get($root . 'labels', array($this, 'translationsGET'))
            ->bind('labels')
        ;
        $this->app->get($root . 'labels/list', array($this, 'listTranslations'))
            ->bind('list_labels')
        ;
        $this->app->post($root . 'labels/save', array($this, 'labelsSavePost'))
            ->bind('save_labels')
        ;
    }

    /**
     * Set the current language
     *
     * @param Request $request
     */
    public function before(Request $request)
    {
        $lang = $this->config['default'];

        $this->jsonFile = $this->app['resources']->getPath('extensionsconfig') . '/labels.json';

        if (!empty($_GET['lang'])) {
            // Language has been passed explicitly as ?lang=xx
            $lang = trim(strtolower($_GET['lang']));
        } elseif (isset($_SERVER['HTTP_HOST'])) {
            if ($extracted = $this->extractLanguage($_SERVER['HTTP_HOST'])) {
                // We're on a language-specific domain
                $lang = $extracted;
            }
        }

        if (!empty($lang) && $this->isValidLanguage($lang)) {
            $this->app['session']->set('lang', $lang);
        }
        if (is_null($this->app['session']->get('lang'))) {
            $this->app['session']->set('lang', 'nl');
        }
        $this->setCurrentLanguage($lang);
    }

    public function extractLanguage($lang)
    {
        $matches = array();
        if (preg_match('/^([a-z]{2})\./', $lang, $matches)) {
            return $matches[1];
        } else {
            return false;
        }
    }

    public function loadLabels()
    {
        $fs = new Filesystem();

        // Check that the user's JSON file exists, else copy in the default
        if (!$fs->exists($this->jsonFile)) {
            try {
                $fs->copy($this->getBasePath() . '/files/labels.json', $this->jsonFile);
            } catch (IOException $e) {
                $this->app['session']->getFlashBag()->set('error',
                    'The labels file at <tt>app/config/extensions/labels.json</tt> does not exist, and can not be created. Changes can NOT saved, until you fix this.');
            }
        }

        // Check the file is writable
        try {
            $fs->touch($this->jsonFile);
        } catch (IOException $e) {
            $this->app['session']->getFlashBag()->set('error',
                'The labels file at <tt>app/config/extensions/labels.json</tt> is not writable. Changes can NOT saved, until you fix this.');
        }

        // Read the contents of the file
        try {
            $finder = new Finder();
            $finder
                ->files()
                ->name('labels.json')
                ->in($this->app['resources']->getPath('extensionsconfig'))
            ;

            foreach ($finder->files() as $file) {
                $this->labels = json_decode($file->getContents(), true);
                continue;
            }
        } catch (\Exception $e) {
            $this->app['session']->getFlashBag()->set('error', sprintf('There was an issue loading the labels: %s', $e->getMessage()));
            $this->labels = false;
        }
    }

    /**
     * Validate a two-letter language code.
     */
    public function isValidLanguage($lang)
    {
        return preg_match('/^[a-z]{2}$/', $lang);
    }

    public function setCurrentLanguage($lang)
    {
        if ($this->isValidLanguage($lang)) {
            // Note: we're not changing the session value here, because we
            // don't want to persist this language override across requests.
            //
            // We're using the Twig global 'lang' as our source of truth, this
            // way we can change the current language from within a template by
            // setting the lang variable.
            $this->app['twig']->addGlobal('lang', $lang);
        }
    }

    public function getCurrentLanguage()
    {
        $twigGlobals = $this->app['twig']->getGlobals();
        if (isset($twigGlobals['lang'])) {
            return $twigGlobals['lang'];
        } else {
            return null;
        }
    }

    public function translationsGET(Request $request)
    {
        $this->requireUserPermission('labels');

        if ($this->labels === null) {
            $this->loadLabels();
        }

        ksort($this->labels);

        $languages = array_map('strtoupper', $this->config['languages']);

        $data = [];

        foreach ($this->labels as $label => $row) {
            $values = [];
            foreach ($languages as $l) {
                $values[] = $row[strtolower($l)] ?: '';
            }
            $data[] = array_merge([$label], $values);
        }

        $twigvars = [
            'columns' => array_merge([ 'Label'], $languages),
            'data'    => $data
        ];

        return $this->render('import_form.twig', $twigvars);
    }

    public function addLabel($label)
    {
        $label = strtolower(trim($label));
        $this->labels[$label] = [];
        $jsonarr = json_encode($this->labels, JSON_PRETTY_PRINT);

        if (!file_put_contents($this->jsonFile, $jsonarr)) {
            echo '[error saving labels]';
        }
    }

    public function labelsSavePost(Request $request)
    {
        $columns = array_map('strtolower', json_decode($request->get('columns')));
        $labels = json_decode($request->get('labels'));

        // remove the label.
        array_shift($columns);

        $arr = [];

        foreach ($labels as $labelrow) {
            $key = strtolower(trim(array_shift($labelrow)));
            $values = array_combine($columns, $labelrow);
            if (!empty($key)) {
                $arr[$key] = $values;
            }
        }

        $jsonarr = json_encode($arr, JSON_PRETTY_PRINT);

        if (strlen($jsonarr) < 50) {
            $this->app['session']->getFlashBag()->set('error', 'There was an issue encoding the file. Changes were NOT saved.');
            return Lib::redirect('labels');
        }

        $fs = new Filesystem();
        try {
            $fs->dumpFile($this->jsonFile, $jsonarr);
            $this->app['session']->getFlashBag()->set('success', 'Changes to the labels have been saved.');
        } catch (IOException $e) {
            $this->app['session']->getFlashBag()->set('error',
                'The labels file at <tt>../app/config/extensions/labels.json</tt> is not writable. Changes were NOT saved.');
        }

        return Lib::redirect('labels');
    }

    /**
     * Twig function {{ l() }} in Labels extension.
     */
    public function twigL($label, $lang =  false)
    {
        $label = strtolower(trim($label));

        if (!$this->isValidLanguage($lang)) {
            $lang = $this->getCurrentLanguage();
        }

        if ($this->labels === null) {
            $this->loadLabels();
        }

        if (!empty($this->labels[$label][strtolower($lang)])) {
            $res = $this->labels[$label][strtolower($lang)];
        } else {
            $res = '<mark>' . $label . '</mark>';

            // Perhaps use the fallback?
            if ($this->config['use_fallback'] && !empty($this->labels[$label][strtolower($this->config['default'])])) {
                $res = $this->labels[$label][strtolower($this->config['default'])];
            }

            // perhaps add it to the labels file?
            if ($this->config['add_missing'] && empty($this->labels[$label])) {
                $this->addLabel($label);
            }
        }

        return new \Twig_Markup($res, 'UTF-8');
    }

    public function twigSetLanguage($lang)
    {
        $this->setCurrentLanguage($lang);
        return '';
    }

    public function __get($name)
    {
        return $name === 'currentLanguage' ? $this->getCurrentLanguage() : null;
    }

    /**
     * {@inheritDoc}
     */
    protected function getDefaultConfig()
    {
        return array(
            'languages'    => array('en'),
            'default'      => 'en',
            'add_missing'  => true,
            'use_fallback' => true
        );
    }

    private function render($template, $data)
    {
        $this->app['twig.loader.filesystem']->addPath(__DIR__ . '/templates');

        if ($this->app['config']->getWhichEnd() === 'backend') {
            $this->app['htmlsnippets'] = true;
            $this->addCss('assets/handsontable.full.min.css');
            $this->addJavascript('assets/handsontable.full.min.js', true);
            $this->addJavascript('assets/start.js', true);
        }

        return $this->app['render']->render($template, $data);
    }
}
