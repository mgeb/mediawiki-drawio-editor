<?php

$wgExtensionCredits['parserhook'][] = array(
   'name' => 'DrawioEditor',
   'version' => "1.0",
   'description' => 'draw.io flow chart creation and inline editing',
   'author' => 'Markus Gebert',
   'url' => 'https://github.com/mgeb/mediawiki-drawio-editor'
);

$wgHooks['ParserFirstCallInit'][] = 'DrawioEditor::onParserSetup';
$wgHooks['OutputPageParserOutput'][] = 'DrawioEditor::onOutputPageParserOutput';

$wgExtensionMessagesFiles['DrawioEditor'] = __DIR__ . '/DrawioEditor.i18n.php';

$wgResourceModules['ext.drawioeditor'] = array(
    'scripts' => 'ext.drawioeditor.js',
    'styles' => 'ext.drawioeditor.css',
    'dependencies' => array('jquery.ui.resizable'),
    'localBasePath' => __DIR__ . '/resources',
    'remoteExtPath' => 'DrawioEditor/resources',
);

/* Config Defaults */
$wgDrawioEditorImageType = 'svg';
$wgDrawioEditorImageInteractive = false;

class DrawioEditor {
    public static function onParserSetup(&$parser) {
        $parser->setFunctionHook( 'drawio', 'DrawioEditor::parse' );
    }

    public static function onOutputPageParserOutput(&$outputPage, $parseroutput) {
        $outputPage->addModules('ext.drawioeditor');
    }

    public static function parse(&$parser, $name=null) {
        global $wgUser, $wgEnableUploads;
        global $wgDrawioEditorImageType;
        global $wgDrawioEditorImageInteractive;
        
        /* disable caching before any output is generated */
        $parser->disableCache();

	/* parse named arguments */
	$opts = array();
        foreach (array_slice(func_get_args(), 2) as $rawopt) {
            $opt = explode('=', $rawopt, 2);
	    $opts[trim($opt[0])] = count($opt) === 2 ? trim($opt[1]) : true;
	}
       
        $opt_type = array_key_exists('type', $opts) ? $opts['type'] : $wgDrawioEditorImageType;
        $opt_interactive = array_key_exists('interactive', $opts) ? true : $wgDrawioEditorImageInteractive;
        $opt_height = array_key_exists('height', $opts) ? $opts['height'] : 'auto';
        $opt_width = array_key_exists('width', $opts) ? $opts['width'] : '100%';
        $opt_max_width = array_key_exists('max-width', $opts) ? $opts['max-width'] : false;

        /* process input */
        if ($name == null || !strlen($name))
            return self::errorMessage('Usage Error');
        if (!in_array($opt_type, ['svg', 'png']))
            return self::errorMessage('Invalid type');

        $len_regex = '/^((0|auto|chart)|[0-9]+(\.[0-9]+)?(px|%|mm|cm|in|em|ex|pt|pc))$/';
        $len_regex_max = '/^((0|none|chart)|[0-9]+(\.[0-9]+)?(px|%|mm|cm|in|em|ex|pt|pc))$/';

        if (!preg_match($len_regex, $opt_height))
            return self::errorMessage('Invalid height');
        if (!preg_match($len_regex, $opt_width))
            return self::errorMessage('Invalid width');

	if ($opt_max_width) {
            if (!preg_match('/%$/', $opt_width))
                return self::errorMessage('max-width is only allowed when width is relative');
            if (!preg_match($len_regex_max, $opt_max_width))
                return self::errorMessage('Invalid max-width');
        } else {
            $opt_max_width = 'chart';
        }

	$name = wfStripIllegalFilenameChars($name);
	$dispname = htmlspecialchars($name, ENT_QUOTES);

        /* random id to reference html elements */
        $id = mt_rand();

        /* prepare image information */
        $img_name = $name.".drawio.".$opt_type;
        $img = wfFindFile($img_name);
        if ($img) {
            $img_url = $img->getUrl();
            $img_url_ts = $img_url.'?ts='.$img->nextHistoryLine()->img_timestamp;
            $img_desc_url = $img->getDescriptionUrl();
	    $img_height = $img->getHeight().'px';
	    $img_width = $img->getWidth().'px';
        } else {
            $img_url = '';
            $img_url_ts = '';
            $img_desc_url = '';
	    $img_height = 0;
	    $img_width = 0;
        }

        $css_img_height = $opt_height === 'chart' ? $img_height : $opt_height;
        $css_img_width = $opt_width === 'chart' ? $img_width : $opt_width;
        $css_img_max_width = $opt_max_width === 'chart' ? $img_width : $opt_max_width;

        /* check for conditions that should or will prevent an edit of the chart */
        $readonly = (!$wgEnableUploads
            || (!$img && !$wgUser->isAllowed('upload'))
            || ($img && !$wgUser->isAllowed('reupload'))
            || $parser->getTitle()->isProtected('edit')
            );

        /* prepare edit href */
        $edit_ahref = sprintf("<a href='javascript:editDrawio(\"%s\", %s, \"%s\", %s, %s, %s, %s)'>",
            $id,
            json_encode($img_name, JSON_HEX_QUOT | JSON_HEX_APOS),
            $opt_type,
            $opt_interactive ? 'true' : 'false',
            $opt_height === 'chart' ? 'true' : 'false',
            $opt_width === 'chart' ? 'true' : 'false',
	    $opt_max_width === 'chart' ? 'true': 'false');
        
        /* output begin */
        $output = '<div>';

        /* div around the image */
        $output .= '<div id="drawio-img-box-'.$id.'">';

        /* display edit link */
        if (!$readonly) {
            $output .= '<div align="right">';
	    $output .= '<span class="mw-editsection">';
	    $output .= '<span class="mw-editsection-bracket">[</span>';
            $output .= $edit_ahref;
            $output .= wfMessage('edit')->text().'</a>';
	    $output .= '<span class="mw-editsection-bracket">]</span>';
	    $output .= '</span>';
	    $output .= '</div>';
        }

        /* prepare image */
        $img_style = sprintf('height: %s; width: %s; max-width: %s;',
                $css_img_height, $css_img_width, $css_img_max_width);
        if (!$img) {
            $img_style .= ' display:none;';
        }

	if ($opt_interactive)
        {
            $img_fmt = '<object id="drawio-img-%s" data="%s" type="text/svg+xml" style="%s"></object>';
            $img_html = sprintf($img_fmt, $id, $img_url_ts, $img_style);
        } else {
            $img_fmt = '<img id="drawio-img-%s" src="%s" title="%s" alt="%s" style="%s"></img>';
            $img_html = '<a id="drawio-img-href-'.$id.'" href="'.$img_desc_url.'">';
            $img_html .= sprintf($img_fmt, $id, $img_url_ts, 'drawio: '.$dispname, 'drawio: '.$dispname, $img_style);
            $img_html .= '</a>';
        }

        /* output image and optionally a placeholder if the image does not exist yet */
        if (!$img) {
            // show placeholder
            $output .= sprintf('<div id="drawio-placeholder-%s" class="DrawioEditorInfoBox">'.
                '<b>%s</b><br/>empty draw.io chart</div> ',
                $id, $dispname);
        }
        // the image or object element must be there' in any case (it's hidden as long as there is no content.
        $output .= $img_html;
        $output .= '</div>';
        
        /* editor and overlay divs, iframe is added by javascript on demand */
        $output .= '<div id="drawio-iframe-box-'.$id.'" style="display:none;">';
	$output .= '<div id="drawio-iframe-overlay-'.$id.'" class="DrawioEditorOverlay" style="display:none;"></div>';
	$output .= '</div>';

        /* output end */
        $output .= '</div>';

        /* 
         * link the image to the ParserOutput, so that the mediawiki knows that
         * it is used by the hosting page (through the DrawioEditor extension).
         * Note: This only works if the page is edited after the image has been
         * created (i.e. saved in the DrawioEditor for the first time).
         */
        if ($img) {
            $parser->getOutput()->addImage($img->getTitle()->getDBkey());
        }

        return array($output, 'isHTML'=>true, 'noparse'=>true);
    }

    private static function errorMessage($msg) {
        $output  = '<div class="DrawioEditorInfoBox" style="border-color:red;">';
        $output .= '<p style="color: red;">DrawioEditor Usage Error:<br/>'.htmlspecialchars($msg).'</p>';
	$output .= '</div>';

        return array($output, 'isHTML'=>true, 'noparse'=>true);
    }
}
?>
