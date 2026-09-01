<?php
/**
 * Websites_Workflow
 *
 * Turn an existing website into one of our Streams-native websites: a Websites/page
 * with related Websites/section streams, each with related Websites/block streams,
 * plus a theme (CSS variables) analyzed from the original and a hero image lifted
 * from it. Built on the same shape as Code_Workflow — a PURE proposer returns a
 * change set, a Q_Branch fork holds the proposal + manifest as files, apply()
 * materializes the streams live at publish time (the only place streams are
 * written), and a manifest + diff are published. Grokers supplies the structural
 * facts (which pages link where, which theme variables the page uses); analyze()
 * supplies the design tokens.
 *
 * @module Websites
 */
class Websites_Workflow
{
    /**
     * Import a source URL into a Streams website.
     * @param {string} $asUserId       who is performing the import
     * @param {string} $publisherId    publisher the new website belongs to
     * @param {string} $sourceUrl      the existing site to analyze and mirror
     * @param {string} $pageStreamName name for the new Websites/page (e.g. "Websites/page/home")
     * @param {array}  $options        baseBranch, workflowBranch, plus overrides
     * @return {array} { page, sections, blocks, changes, manifest, branch }
     */
    static function importSite($asUserId, $publisherId, $sourceUrl, $pageStreamName, $options = array())
    {
        // 1. ANALYZE the original — design tokens, colors, fonts, nav, hero (no writes).
        $analysis = Websites_Webpage::analyze($sourceUrl, Q::ifset($options, 'analyze', array()));

        // 2. PROPOSE — pure: build the change set from the analysis. Touches nothing live.
        $changes = self::propose_import($publisherId, $pageStreamName, $sourceUrl, $analysis, $options);

        // 3. FORK a copy-on-write branch and record the proposal as files (Q_Branch is files only).
        $base   = Q_Branch::of(Q::ifset($options, 'baseBranch', 'websites/main'), $options);
        $branch = $base->fork(Q::ifset($options, 'workflowBranch', 'websites/import-' . time()), $options);
        $branch->write('workflow/import.json', Q::json_encode($changes));

        // 4. APPLY — the ONLY place streams are materialized. Sandbox it if Safebox is present.
        $applier = function () use ($changes, $asUserId, $options) {
            return self::apply($changes, $asUserId, $options);
        };
        $result = class_exists('Safebox') ? Safebox::run($applier) : $applier();

        // 5. PUBLISH — manifest + diff back to the branch.
        $manifest = self::manifest($sourceUrl, $changes, $result);
        $branch->write('workflow/import.manifest.json', Q::json_encode($manifest));
        $diff = $base->diff($branch);

        return array(
            'page'     => $result['page'],
            'sections' => $result['sections'],
            'blocks'   => $result['blocks'],
            'changes'  => $changes,
            'manifest' => $manifest,
            'diff'     => $diff,
            'branch'   => $branch
        );
    }

    /**
     * PURE proposer: analysis -> a change set. No live streams touched.
     * A change set is { theme, page, sections:[{ ..., blocks:[...] }] }, with weights
     * that fix ascending order, so apply() can materialize deterministically.
     */
    static function propose_import($publisherId, $pageStreamName, $sourceUrl, $analysis, $options)
    {
        $theme = self::themeFromAnalysis($analysis);
        $hero  = self::heroFromAnalysis($analysis, $sourceUrl);
        $title = Q::ifset($analysis, 'title', $sourceUrl);

        $sections = array();
        $weight = 0;

        // Hero section first, if we found a usable image.
        if ($hero) {
            $sections[] = array(
                'name'   => $pageStreamName . '/section/hero',
                'title'  => 'Hero',
                'weight' => ++$weight,
                'blocks' => array(
                    array('type' => 'image',   'weight' => 1, 'attributes' => array('src' => $hero, 'alt' => $title)),
                    array('type' => 'heading', 'weight' => 2, 'attributes' => array('text' => $title))
                )
            );
        }

        // Turn the detected nav into content sections (one per nav item), else a single body section.
        $nav = Q::ifset($analysis, 'detectedNav', 'items', array());
        if ($nav) {
            foreach ($nav as $i => $item) {
                $label = is_array($item) ? Q::ifset($item, 'text', 'Section') : (string)$item;
                $sections[] = array(
                    'name'   => $pageStreamName . '/section/' . Q_Utils::normalize($label),
                    'title'  => $label,
                    'weight' => ++$weight,
                    'blocks' => array(
                        array('type' => 'heading', 'weight' => 1, 'attributes' => array('text' => $label))
                    )
                );
            }
        } else {
            $sections[] = array(
                'name'   => $pageStreamName . '/section/body',
                'title'  => 'Body',
                'weight' => ++$weight,
                'blocks' => array(
                    array('type' => 'text', 'weight' => 1, 'attributes' => array(
                        'text' => Q::ifset($analysis, 'description', 'Imported from ' . $sourceUrl)
                    ))
                )
            );
        }

        return array(
            'publisherId' => $publisherId,
            'theme'       => $theme,
            'page'        => array('name' => $pageStreamName, 'title' => $title, 'source' => $sourceUrl),
            'sections'    => $sections
        );
    }

    /**
     * Apply a pure change set to live streams. The ONLY writer of streams.
     * Materializes page -> sections -> blocks and relates them (ascending weight),
     * and stores the theme's CSS variables as an attribute on the page.
     */
    protected static function apply($changes, $asUserId, $options)
    {
        $publisherId = $changes['publisherId'];
        $skip = array('skipAccess' => true);

        // Page.
        $page = Streams::create($asUserId, $publisherId, 'Websites/page', array(
            'name'    => $changes['page']['name'],
            'title'   => $changes['page']['title'],
            'content' => $changes['page']['source']
        ), $skip);
        // Theme lives as CSS variables on the page — the tokens Websites_Theme::generateCSS() consumes.
        $page->setAttribute('Websites/theme', $changes['theme']);
        $page->changed($asUserId);

        $sections = array();
        $blocks   = array();
        foreach ($changes['sections'] as $s) {
            $section = Streams::create($asUserId, $publisherId, 'Websites/section', array(
                'name'  => $s['name'],
                'title' => $s['title']
            ), $skip);
            Streams::relate($asUserId, $publisherId, $page->name, 'Websites/sections',
                $publisherId, $section->name, array('weight' => $s['weight'], 'skipAccess' => true));
            $sections[] = $section;

            foreach ($s['blocks'] as $b) {
                $block = Streams::create($asUserId, $publisherId, 'Websites/block', array(
                    'name'       => $s['name'] . '/block/' . $b['type'] . '/' . $b['weight'],
                    'attributes' => Q::json_encode(array_merge(array('type' => $b['type']), $b['attributes']))
                ), $skip);
                Streams::relate($asUserId, $publisherId, $section->name, 'Websites/blocks',
                    $publisherId, $block->name, array('weight' => $b['weight'], 'skipAccess' => true));
                $blocks[] = $block;
            }
        }

        return array('page' => $page, 'sections' => $sections, 'blocks' => $blocks);
    }

    /**
     * Map analyze() output -> CSS variables. rootThemeColors already come as
     * {--name: hex}; colorRoles gives foreground/background; fonts gives the stack.
     */
    static function themeFromAnalysis($analysis)
    {
        $vars = Q::ifset($analysis, 'rootThemeColors', array());
        $fg = Q::ifset($analysis, 'colorRoles', 'foreground', 0, 'hex', null);
        $bg = Q::ifset($analysis, 'colorRoles', 'background', 0, 'hex', null);
        if ($fg) { $vars['--foreground'] = $fg; }
        if ($bg) { $vars['--background'] = $bg; }
        $fonts = Q::ifset($analysis, 'fonts', array());
        if (!empty($fonts)) { $vars['--font-family'] = implode(', ', array_slice($fonts, 0, 3)); }
        return array('cssVariables' => $vars, 'fonts' => $fonts);
    }

    /**
     * The hero image: the analyzed og:image / largest image, absolute.
     */
    static function heroFromAnalysis($analysis, $sourceUrl)
    {
        $hero = Q::ifset($analysis, 'hero', Q::ifset($analysis, 'ogImage', null));
        if (!$hero) {
            $images = Q::ifset($analysis, 'images', array());
            if (!empty($images)) { $hero = is_array($images[0]) ? Q::ifset($images[0], 'src', null) : $images[0]; }
        }
        if ($hero && strpos($hero, '//') === false) {
            $hero = rtrim($sourceUrl, '/') . '/' . ltrim($hero, '/');
        }
        return $hero;
    }

    static function manifest($sourceUrl, $changes, $result)
    {
        return array(
            'source'   => $sourceUrl,
            'page'     => $result['page']->name,
            'sections' => count($result['sections']),
            'blocks'   => count($result['blocks']),
            'theme'    => array_keys(Q::ifset($changes, 'theme', 'cssVariables', array())),
            'at'       => time()
        );
    }
}