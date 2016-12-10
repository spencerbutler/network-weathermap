<?php
// PHP Weathermap 0.98
// Copyright Howard Jones, 2005-2016 howie@thingy.com
// http://www.network-weathermap.com/
// Released under the GNU Public License

require_once "HTML_ImageMap.class.php";

class WeatherMapNode extends WeatherMapDataItem
{
    var $drawable;
    var $x, $y;
    var $original_x, $original_y, $relative_resolved;
    var $width, $height;
    var $label, $proclabel;
    var $labelangle;
    var $selected = 0;
    var $position;

    var $pos_named;
    var $named_offsets;
    var $relative_name;

    var $iconfile, $iconscalew, $iconscaleh;
    var $labeloffset, $labeloffsetx, $labeloffsety;

    /** @var  WMColour $labelbgcolour */
    var $labelbgcolour;
    /** @var  WMColour $labeloutlinecolour */
    var $labeloutlinecolour;
    /** @var  WMColour $labelfontcolour */
    var $labelfontcolour;
    /** @var  WMColour $labelfontshadowcolour */
    var $labelfontshadowcolour;

    var $labelfont;

    var $useiconscale;
    var $iconscaletype;
    var $iconscalevar;
    var $image;
    var $centre_x, $centre_y; // TODO these were for ORIGIN
    var $relative_to;
    var $polar;
    var $boundingboxes = array();
    /** @var  WMColour $aiconfillcolour */
    public $aiconfillcolour;
    /** @var  WMColour $aiconoutlinecolour */
    public $aiconoutlinecolour;

    /**
     * WeatherMapNode constructor.
     *
     * @param string $name
     * @param string $template
     * @param WeatherMap $owner
     */
    function __construct($name, $template, $owner)
    {
        parent::__construct();

        $this->name = $name;
        $this->owner = $owner;
        $this->template = $template;

        $this->width = 0;
        $this->height = 0;
        $this->centre_x = 0;
        $this->centre_y = 0;
        $this->polar = false;
        $this->pos_named = false;
        $this->image = null;
        $this->drawable = false;

        $this->inherit_fieldlist = array
        (
            'boundingboxes' => array(),
            'my_default' => NULL,
            'label' => '',
            'proclabel' => '',
            'usescale' => 'DEFAULT',
            'scaletype' => 'percent',
            'iconscaletype' => 'percent',
            'useiconscale' => 'none',
            'scalevar' => 'in',
            'template' => ':: DEFAULT ::',
            'iconscalevar' => 'in',
            'labelfont' => 3,
            'relative_to' => '',
            'relative_resolved' => FALSE,
            'x' => NULL,
            'y' => NULL,
            'inscalekey' => '', 'outscalekey' => '',
            #'incolour'=>-1,'outcolour'=>-1,
            'original_x' => 0,
            'original_y' => 0,
            'labelangle' => 0,
            'iconfile' => '',
            'iconscalew' => 0,
            'iconscaleh' => 0,
            'targets' => array(),
            'named_offsets' => array(),
            'infourl' => array(IN => '', OUT => ''),
            'maxValuesConfigured' => array(IN => "100", OUT => "100"),
            'maxValues' => array(IN => null, OUT => null),
            'notestext' => array(IN => '', OUT => ''),
            'notes' => array(),
            'hints' => array(),
            'overliburl' => array(IN => array(), OUT => array()),
            'overlibwidth' => 0,
            'overlibheight' => 0,
            'overlibcaption' => array(IN => '', OUT => ''),

            'labeloutlinecolour' => new WMColour(0, 0, 0),
            'labelbgcolour' => new WMColour(255, 255, 255),
            'labelfontcolour' => new WMColour(0, 0, 0),
            'labelfontshadowcolour' => new WMColour('none'),
            'aiconoutlinecolour' => new WMColour(0, 0, 0),
            'aiconfillcolour' => new WMColour('copy'), // copy from the node label

            'labeloffset' => '',
            'labeloffsetx' => 0,
            'labeloffsety' => 0,
            'zorder' => 600,
        );

        $this->reset($owner);
    }

    function Reset(&$newowner)
    {
        $this->owner = $newowner;
        $template = $this->template;

        if ($template == '') $template = "DEFAULT";

        wm_debug("Resetting $this->name with $template\n");

        // the internal default-default gets it's values from inherit_fieldlist
        // everything else comes from a node object - the template.
        if ($this->name == ':: DEFAULT ::') {
            foreach (array_keys($this->inherit_fieldlist) as
                     $fld) {
                $this->$fld = $this->inherit_fieldlist[$fld];
            }
        } else {
            $this->CopyFrom($this->owner->nodes[$template]);
        }
        $this->template = $template;

        // to stop the editor tanking, now that colours are decided earlier in ReadData
        $this->colours[IN] = new WMColour(192, 192, 192);
        $this->colours[OUT] = new WMColour(192, 192, 192);

        $this->id = $newowner->next_id++;
    }

    function my_type()
    {
        return "NODE";
    }

    /**
     * @param resource $im
     * @param WeatherMap $map
     */
    function pre_render($im, &$map)
    {
        if (!$this->drawable) {
            wm_debug("Skipping undrawable %s", $this);
            return;
        }

        // don't bother drawing if it's a template
        if ($this->isTemplate()) return;

        // apparently, some versions of the gd extension will crash
        // if we continue...
        if ($this->label == '' && $this->iconfile == '') return;

        // start these off with sensible values, so that bbox
        // calculations are easier.

        $txt_x = $this->x;
        $txt_y = $this->y;

        $icon_x1 = $this->x;
        $icon_x2 = $this->x;
        $icon_y1 = $this->y;
        $icon_y2 = $this->y;

        $label_x1 = $this->x;
        $label_x2 = $this->x;
        $label_y1 = $this->y;
        $label_y2 = $this->y;

        $boundingBox = new WMBoundingBox();
        $labelBox = new WMRectangle($this->x, $this->y, $this->x, $this->y);
        $iconBox = new WMRectangle($this->x, $this->y, $this->x, $this->y);
        $textPoint = new WMPoint($this->x, $this->y);

        $labelBoxWidth = 0;
        $labelBoxHeight = 0;
        $icon_w = 0;
        $icon_h = 0;

        $col = new WMColour('none');

        // if a target is specified, and you haven't forced no background, then the background will
        // come from the SCALE in USESCALE
        if (!empty($this->targets) && $this->usescale != 'none') {
            $pc = 0;

            if ($this->scalevar == 'in') {
                $pc = $this->percentUsages[IN];
                $col = $this->colours[IN];

            }

            if ($this->scalevar == 'out') {
                $pc = $this->percentUsages[OUT];
                $col = $this->colours[OUT];

            }
        } elseif (!$this->labelbgcolour->isNone()) {
            wm_debug("labelbgcolour=%s\n", $this->labelbgcolour);
            // $col=myimagecolorallocate($node_im, $this->labelbgcolour[0], $this->labelbgcolour[1], $this->labelbgcolour[2]);
            $col = $this->labelbgcolour;
        }

        $colicon = null;
        if (!empty($this->targets) && $this->useiconscale != 'none') {
            wm_debug("Colorising the icon\n");
            $pc = 0;
            $val = 0;

            if ($this->iconscalevar == 'in') {
                $pc = $this->percentUsages[IN];
                //$col = $this->colours[IN];
                $val = $this->absoluteUsages[IN];
            }
            if ($this->iconscalevar == 'out') {
                $pc = $this->percentUsages[OUT];
                //$col = $this->colours[OUT];
                $val = $this->absoluteUsages[OUT];
            }

            if ($this->iconscaletype == 'percent') {
                list($colicon, $node_iconscalekey, $icontag) =
                    $map->scales[$this->useiconscale]->colourFromValue($pc, $this->name);
            } else {
                // use the absolute value if we aren't doing percentage scales.
                list($colicon, $node_iconscalekey, $icontag) =
                    $map->scales[$this->useiconscale]->colourFromValue($val, $this->name, false);
            }
        }

        // figure out a bounding rectangle for the label
        if ($this->label != '') {
            $paddingConstant = 4.0;
            $paddingFactor = 1.0;

            $this->proclabel = $map->ProcessString($this->label, $this, TRUE, TRUE);

            // if screenshot_mode is enabled, wipe any letters to X and wipe any IP address to 127.0.0.1
            // hopefully that will preserve enough information to show cool stuff without leaking info
            if ($map->get_hint('screenshot_mode') == 1) {
                $this->proclabel = WMUtility::stringAnonymise($this->proclabel);
            }

            $fontObject = $this->owner->fonts->getFont($this->labelfont);
            list($stringWidth, $stringHeight) = $fontObject->calculateImageStringSize($this->proclabel);

            if ($this->labelangle == 90 || $this->labelangle == 270) {
                $labelBoxWidth = $stringHeight * $paddingFactor + $paddingConstant;
                $labelBoxHeight = $stringWidth * $paddingFactor + $paddingConstant;
                wm_debug("Node->pre_render: " . $this->name . " Label Metrics are: $stringWidth x $stringHeight -> $labelBoxWidth x $labelBoxHeight\n");

                if ($this->labelangle == 90) {
                    $txt_x = $this->x + ($stringHeight / 2);
                    $txt_y = $this->y + ($stringWidth / 2);
                    $textPoint = new WMPoint($stringHeight/2, $stringWidth/2);
                }
                if ($this->labelangle == 270) {
                    $txt_x = $this->x - ($stringHeight / 2);
                    $txt_y = $this->y - ($stringWidth / 2);
                    $textPoint = new WMPoint(-$stringHeight/2, -$stringWidth/2);
                }
            }

            if ($this->labelangle == 0 || $this->labelangle == 180) {
                $labelBoxWidth = $stringWidth * $paddingFactor + $paddingConstant;
                $labelBoxHeight = $stringHeight * $paddingFactor + $paddingConstant;
                wm_debug("Node->pre_render: " . $this->name . " Label Metrics are: $stringWidth x $stringHeight -> $labelBoxWidth x $labelBoxHeight\n");

                if ($this->labelangle == 0) {
                    $txt_x = $this->x - ($stringWidth / 2);
                    $txt_y = $this->y + ($stringHeight / 2);
                    $textPoint = new WMPoint(-$stringHeight / 2, $stringWidth / 2);
                }

                if ($this->labelangle == 180) {
                    $txt_x = $this->x + ($stringWidth / 2);
                    $txt_y = $this->y - ($stringHeight / 2);
                    $textPoint = new WMPoint(-$stringHeight/2, -$stringWidth/2);
                }
            }

            $label_x1 = $this->x - ($labelBoxWidth / 2);
            $label_y1 = $this->y - ($labelBoxHeight / 2);

            $label_x2 = $this->x + ($labelBoxWidth / 2);
            $label_y2 = $this->y + ($labelBoxHeight / 2);

            $textPoint->translate($this->x, $this->y);

            $labelBox = new WMRectangle(-$labelBoxWidth/2, -$labelBoxHeight/2, $labelBoxWidth/2, $labelBoxHeight/2);
            $labelBox->translate($this->x, $this->y);

            wm_debug("LABEL at %s\n", $labelBox);

//            $map->nodes[$this->name]->width = $boxwidth;
//            $map->nodes[$this->name]->height = $boxheight;
            $this->width = $labelBoxWidth;
            $this->height = $labelBoxHeight;

            # print "TEXT at $txt_x , $txt_y\n";
        }

        //********************************

        // figure out a bounding rectangle for the icon
        if ($this->iconfile != '') {
            $icon_im = NULL;
            $icon_w = 0;
            $icon_h = 0;

            if ($this->iconfile == 'rbox' || $this->iconfile == 'box' || $this->iconfile == 'round' || $this->iconfile == 'inpie' || $this->iconfile == 'outpie' || $this->iconfile == 'gauge' || $this->iconfile == 'nink') {
                $icon_im = $this->drawArtificialIcon($map, $col);
            }  else {
                $icon_im = $this->drawRealIcon($map, $colicon);
            }

            //********************************

            if ($icon_im) {
                $icon_w = imagesx($icon_im);
                $icon_h = imagesy($icon_im);

                $icon_x1 = $this->x - $icon_w / 2;
                $icon_y1 = $this->y - $icon_h / 2;
                $icon_x2 = $this->x + $icon_w / 2;
                $icon_y2 = $this->y + $icon_h / 2;

                $iconBox = new WMRectangle(-$icon_w/2, -$icon_h/2, $icon_w/2, $icon_h/2);
                $iconBox->translate($this->x, $this->y);

                $this->width = $icon_w;
                $this->height = $icon_h;

                $this->boundingboxes[] = array($icon_x1, $icon_y1, $icon_x2, $icon_y2);
                $boundingBox->addRectangle($iconBox);
            }
        }

        // do any offset calculations
        $dx = 0;
        $dy = 0;
        if (($this->labeloffset != '') && (($this->iconfile != ''))) {
            $this->labeloffsetx = 0;
            $this->labeloffsety = 0;

            list($dx, $dy) = WMUtility::calculateOffset($this->labeloffset,
                ($icon_w + $labelBoxWidth - 1),
                ($icon_h + $labelBoxHeight)
            );
        }

        $label_x1 += ($this->labeloffsetx + $dx);
        $label_x2 += ($this->labeloffsetx + $dx);
        $label_y1 += ($this->labeloffsety + $dy);
        $label_y2 += ($this->labeloffsety + $dy);

        $labelBox->translate($this->labeloffsetx + $dx, $this->labeloffsety + $dy);

        if ($this->label != '') {
            $this->boundingboxes[] = array($label_x1, $label_y1, $label_x2, $label_y2);
            $boundingBox->addRectangle($labelBox);
        }

        // work out the bounding box of the whole thing

        $bbox_x1 = min($label_x1, $icon_x1);
        $bbox_x2 = max($label_x2, $icon_x2) + 1;
        $bbox_y1 = min($label_y1, $icon_y1);
        $bbox_y2 = max($label_y2, $icon_y2) + 1;

        $totalBoundingBox = $boundingBox->getBoundingRectangle();

        // create TWO imagemap entries - one for the label and one for the icon
        // (so we can have close-spaced icons better)

        $temp_width = $bbox_x2 - $bbox_x1;
        $temp_height = $bbox_y2 - $bbox_y1;
        // create an image of that size and draw into it
        $node_im = imagecreatetruecolor($temp_width, $temp_height);
        // ImageAlphaBlending($node_im, FALSE);
        //imagesavealpha($node_im, true);

        $nothing = imagecolorallocatealpha($node_im, 128, 0, 0, 127);
        imagefill($node_im, 0, 0, $nothing);

        $label_x1 -= $bbox_x1;
        $label_x2 -= $bbox_x1;
        $label_y1 -= $bbox_y1;
        $label_y2 -= $bbox_y1;

        $labelBox->translate(-$totalBoundingBox->topLeft->x, -$totalBoundingBox->topLeft->y);

        $icon_x1 -= $bbox_x1;
        $icon_x2 -= $bbox_x1;
        $icon_y1 -= $bbox_y1;
        $icon_y2 -= $bbox_y1;
        $iconBox->translate(-$totalBoundingBox->topLeft->x, -$totalBoundingBox->topLeft->y);

        // Draw the icon, if any
        if (isset($icon_im)) {
            imagealphablending($node_im, true);
            imagecopy($node_im, $icon_im, $icon_x1, $icon_y1, 0, 0, imagesx($icon_im), imagesy($icon_im));
            imagedestroy($icon_im);
        }

        // Draw the label, if any
        if ($this->label != '') {
            $textPoint->translate(-$totalBoundingBox->topLeft->x, -$totalBoundingBox->topLeft->y);
            $textPoint->translate($this->labeloffsetx+$dx, $this->labeloffsety+$dy);
            $txt_x -= $bbox_x1;
            $txt_x += ($this->labeloffsetx + $dx);
            $txt_y -= $bbox_y1;
            $txt_y += ($this->labeloffsety + $dy);

            #       print "FINAL TEXT at $txt_x , $txt_y\n";

            wm_debug("Label colour is $col\n");

            // if there's an icon, then you can choose to have no background
            // if (! $col->isNone() )
            if (!$this->labelbgcolour->isNone()) {
                imagefilledrectangle($node_im, $label_x1, $label_y1, $label_x2, $label_y2, $col->gdallocate($node_im));
            }

            if ($this->selected) {
                imagerectangle($node_im, $label_x1, $label_y1, $label_x2, $label_y2, $map->selected);
                // would be nice if it was thicker, too...
                imagerectangle($node_im, $label_x1 + 1, $label_y1 + 1, $label_x2 - 1, $label_y2 - 1, $map->selected);
            } else {
                $olcol = $this->labeloutlinecolour;
                if ($olcol->isRealColour()) {
                    imagerectangle($node_im, $label_x1, $label_y1, $label_x2, $label_y2, $olcol->gdAllocate($node_im));
                }
            }
            #}

            $fontObject = $this->owner->fonts->getFont($this->labelfont);

            $shcol = $this->labelfontshadowcolour;
            if ($shcol->isRealColour()) {
                $fontObject->drawImageString($node_im, $txt_x + 1, $txt_y + 1, $this->proclabel, $shcol->gdAllocate($node_im), $this->labelangle);
            }

            $txcol = $this->labelfontcolour;

            if ($txcol->isContrast()) {
                if ($col->isRealColour()) {
                    $txcol = $col->getContrastingColour();
                } else {
                    wm_warn("You can't make a contrast with 'none'. Guessing black. [WMWARN43]\n");
                    $txcol = new WMColour(0, 0, 0);
                }
            }
            $fontObject->drawImageString($node_im, $txt_x, $txt_y, $this->proclabel, $txcol->gdAllocate($node_im), $this->labelangle);
        }

        $this->centre_x = $this->x - $bbox_x1;
        $this->centre_y = $this->y - $bbox_y1;

        $this->image = $node_im;

        $this->makeImageMapAreas();
    }

    function isTemplate()
    {
        return is_null($this->x);
    }

    // make a mini-image, containing this node and nothing else
    // figure out where the real NODE centre is, relative to the top-left corner.

    private function makeImageMapAreas()
    {
        $index = 0;
        foreach ($this->boundingboxes as $bbox) {
            $areaName = "NODE:N" . $this->id . ":" . $index;
            $newArea = new HTML_ImageMap_Area_Rectangle($areaName, "", array($bbox));
            wm_debug("Adding imagemap area [" . join(",", $bbox) . "] => $newArea \n");
            $this->imap_areas[] = $newArea;
            $index++;
        }
    }

    function update_cache($cachedir, $mapname)
    {
        $cachename = $cachedir . "/node_" . md5($mapname . "/" . $this->name) . ".png";
        // save this image to a cache, for the editor
        imagepng($this->image, $cachename);
    }

    /**
     * precalculate the colours to be used, and the bounding boxes for labels and icons (if they exist)
     *
     * This is the only stuff that needs to be done if we're doing an editing pass. No actual drawing is necessary.
     *
     * @param WeatherMap $owner
     *
     */
    public function preCalculate(&$owner)
    {
        wm_debug("------------------------------------------------\n");
        wm_debug("Calculating node geometry for %s\n", $this);

        $this->drawable = false;

        // don't bother drawing if it's a template
        if ($this->isTemplate()) {
            wm_debug("%s is a pure template. Skipping.\n", $this);
            return;
        }

        // apparently, some versions of the gd extension will crash if we continue...
        if ($this->label == '' && $this->iconfile == '') {
            wm_debug("%s has no label OR icon. Skipping.\n", $this);
            return;
        }

        $this->drawable = true;
    }

    // draw the node, using the pre_render() output
    function Draw($im, &$map)
    {
        if (!$this->drawable) {
            wm_debug("Skipping undrawable %s\n", $this);
            return;
        }

        // take the offset we figured out earlier, and just blit
        // the image on. Who says "blit" anymore?

        // it's possible that there is no image, so better check.
        if (isset($this->image)) {
            imagealphablending($im, true);
            imagecopy($im, $this->image, $this->x - $this->centre_x, $this->y - $this->centre_y, 0, 0, imagesx($this->image), imagesy($this->image));
        }

    }

    function WriteToCache()
    {
    }

    // take the pre-rendered node and write it to a file so that
    // the editor can get at it.

    function WriteConfig()
    {
        $output = '';

        // This allows the editor to wholesale-replace a single node's configuration
        // at write-time - it should include the leading NODE xyz line (to allow for renaming)
        if ($this->config_override != '') {
            $output = $this->config_override . "\n";
        } else {
            $dd = $this->owner->nodes[$this->template];

            wm_debug("Writing config for NODE $this->name against $this->template\n");

            $basic_params = array(
                # array('template','TEMPLATE',self::CONFIG_TYPE_LITERAL),
                array('label', 'LABEL', self::CONFIG_TYPE_LITERAL),
                array('zorder', 'ZORDER', self::CONFIG_TYPE_LITERAL),
                array('labeloffset', 'LABELOFFSET', self::CONFIG_TYPE_LITERAL),
                array('labelfont', 'LABELFONT', self::CONFIG_TYPE_LITERAL),
                array('labelangle', 'LABELANGLE', self::CONFIG_TYPE_LITERAL),
                array('overlibwidth', 'OVERLIBWIDTH', self::CONFIG_TYPE_LITERAL),
                array('overlibheight', 'OVERLIBHEIGHT', self::CONFIG_TYPE_LITERAL),

                array('aiconoutlinecolour', 'AICONOUTLINECOLOR', self::CONFIG_TYPE_COLOR),
                array('aiconfillcolour', 'AICONFILLCOLOR', self::CONFIG_TYPE_COLOR),
                array('labeloutlinecolour', 'LABELOUTLINECOLOR', self::CONFIG_TYPE_COLOR),
                array('labelfontshadowcolour', 'LABELFONTSHADOWCOLOR', self::CONFIG_TYPE_COLOR),
                array('labelbgcolour', 'LABELBGCOLOR', self::CONFIG_TYPE_COLOR),
                array('labelfontcolour', 'LABELFONTCOLOR', self::CONFIG_TYPE_COLOR)
            );

            # TEMPLATE must come first. DEFAULT
            if ($this->template != 'DEFAULT' && $this->template != ':: DEFAULT ::') {
                $output .= "\tTEMPLATE " . $this->template . "\n";
            }

            $output .= $this->getSimpleConfig($basic_params, $dd, $output);


            // IN/OUT are the same, so we can use the simpler form here
            if ($this->infourl[IN] != $dd->infourl[IN]) {
                $output .= "\tINFOURL " . $this->infourl[IN] . "\n";
            }

            if ($this->overlibcaption[IN] != $dd->overlibcaption[IN]) {
                $output .= "\tOVERLIBCAPTION " . $this->overlibcaption[IN] . "\n";
            }

            // IN/OUT are the same, so we can use the simpler form here
            if ($this->notestext[IN] != $dd->notestext[IN]) {
                $output .= "\tNOTES " . $this->notestext[IN] . "\n";
            }

            if ($this->overliburl[IN] != $dd->overliburl[IN]) {
                $output .= "\tOVERLIBGRAPH " . join(" ", $this->overliburl[IN]) . "\n";
            }

            $val = $this->iconscalew . " " . $this->iconscaleh . " " . $this->iconfile;

            $comparison = $dd->iconscalew . " " . $dd->iconscaleh . " " . $dd->iconfile;

            if ($val != $comparison) {
                $output .= "\tICON ";
                if ($this->iconscalew > 0) {
                    $output .= $this->iconscalew . " " . $this->iconscaleh . " ";
                }
                $output .= ($this->iconfile == '' ? 'none' : $this->iconfile) . "\n";
            }

            if ($this->targets != $dd->targets) {
                $output .= "\tTARGET";

                foreach ($this->targets as $target) {
                    $output .= " " . $target->asConfig();
                }

                $output .= "\n";
            }

            $val = $this->usescale . " " . $this->scalevar . " " . $this->scaletype;
            $comparison = $dd->usescale . " " . $dd->scalevar . " " . $dd->scaletype;

            if (($val != $comparison)) {
                $output .= "\tUSESCALE " . $val . "\n";
            }

            $val = $this->useiconscale . " " . $this->iconscalevar;
            $comparison = $dd->useiconscale . " " . $dd->iconscalevar;

            if ($val != $comparison) {
                $output .= "\tUSEICONSCALE " . $val . "\n";
            }

            $val = $this->labeloffsetx . " " . $this->labeloffsety;
            $comparison = $dd->labeloffsetx . " " . $dd->labeloffsety;

            if ($comparison != $val) {
                $output .= "\tLABELOFFSET " . $val . "\n";
            }

            $val = $this->x . " " . $this->y;
            $comparison = $dd->x . " " . $dd->y;

            if ($val != $comparison) {
                if ($this->relative_to == '') {
                    $output .= "\tPOSITION " . $val . "\n";
                } else {
                    if ($this->polar) {
                        $output .= "\tPOSITION " . $this->relative_to . " " . $this->original_x . "r" . $this->original_y . "\n";
                    } elseif ($this->pos_named) {
                        $output .= "\tPOSITION " . $this->relative_to . ":" . $this->relative_name . "\n";
                    } else {
                        $output .= "\tPOSITION " . $this->relative_to . " " . $this->original_x . " " . $this->original_y . "\n";
                    }
                }
            }

            $output .= $this->getMaxValueConfig($dd, "MAXVALUE");

            $output .= $this->getHintConfig($dd);

            foreach ($this->named_offsets as $off_name => $off_pos) {
                // if the offset exists with different values, or
                // doesn't exist at all in the template, we need to write
                // some config for it
                if ((array_key_exists($off_name, $dd->named_offsets))) {
                    $offsetX = $dd->named_offsets[$off_name][0];
                    $offsetY = $dd->named_offsets[$off_name][1];

                    if ($offsetX != $off_pos[0] || $offsetY != $off_pos[1]) {
                        $output .= sprintf("\tDEFINEOFFSET %s %d %d\n", $off_name, $off_pos[0], $off_pos[1]);
                    }
                } else {
                    $output .= sprintf("\tDEFINEOFFSET %s %d %d\n", $off_name, $off_pos[0], $off_pos[1]);
                }
            }

            if ($output != '') {
                $output = "NODE " . $this->name . "\n$output\n";
            }
        }
        return $output;
    }

    function asJSCore()
    {
        $output = "";

        $output .= "x:" . (is_null($this->x) ? "'null'" : $this->x) . ", ";
        $output .= "y:" . (is_null($this->y) ? "'null'" : $this->y) . ", ";
        $output .= "\"id\":" . $this->id . ", ";
        $output .= "ox:" . $this->original_x . ", ";
        $output .= "oy:" . $this->original_y . ", ";
        $output .= "relative_to:" . WMUtility::jsEscape($this->relative_to) . ", ";
        $output .= "label:" . WMUtility::jsEscape($this->label) . ", ";
        $output .= "name:" . WMUtility::jsEscape($this->name) . ", ";
        $output .= "infourl:" . WMUtility::jsEscape($this->infourl[IN]) . ", ";
        $output .= "overlibcaption:" . WMUtility::jsEscape($this->overlibcaption[IN]) . ", ";
        $output .= "overliburl:" . WMUtility::jsEscape(join(" ", $this->overliburl[IN])) . ", ";
        $output .= "overlibwidth:" . $this->overlibheight . ", ";
        $output .= "overlibheight:" . $this->overlibwidth . ", ";
        if (sizeof($this->boundingboxes) > 0) {
            $output .= sprintf("bbox:[%d,%d, %d,%d], ", $this->boundingboxes[0][0], $this->boundingboxes[0][1],
                $this->boundingboxes[0][2], $this->boundingboxes[0][3]);
        } else {
            $output .= "bbox: [], ";
        }

        if (preg_match("/^(none|nink|inpie|outpie|box|rbox|gauge|round)$/", $this->iconfile)) {
            $output .= "iconfile:" . WMUtility::jsEscape("::" . $this->iconfile);
        } else {
            $output .= "iconfile:" . WMUtility::jsEscape($this->iconfile);
        }

        return $output;
    }

    function asJS($type = "Node", $prefix = "N")
    {
        return parent::asJS($type, $prefix);
    }

    public function isRelativePositionResolved()
    {
        return $this->relative_resolved;
    }

    public function isRelativePositioned()
    {
        if ($this->relative_to != "") {
            return true;
        }

        return false;
    }

    public function getRelativeAnchor()
    {
        return $this->relative_to;
    }

    /**
     * @param WeatherMapNode $anchorNode
     * @return bool
     */
    public function resolveRelativePosition($anchorNode)
    {
        $anchorPosition = $anchorNode->getPosition();

        if ($this->polar) {
            // treat this one as a POLAR relative coordinate.
            // - draw rings around a node!
            $angle = $this->x;
            $distance = $this->y;

            $now = $anchorPosition->copy();
            $now->translatePolar($angle, $distance);
            wm_debug("POLAR $this -> $now\n");
            $this->setPosition($now);
            $this->relative_resolved = true;

            return true;
        }

        if ($this->pos_named) {
            $off_name = $this->relative_name;
            if (isset($anchorNode->named_offsets[$off_name])) {
                $now = $anchorPosition->copy();
                $now->translate(
                    $anchorNode->named_offsets[$off_name][0],
                    $anchorNode->named_offsets[$off_name][1]
                );
                wm_debug("NAMED OFFSET $this -> $now\n");
                $this->setPosition($now);
                $this->relative_resolved = true;

                return true;
            }
            wm_debug("Fell through named offset.\n");

            return false;
        }

        // resolve the relative stuff
        $now = $this->getPosition();
        $now->translate($anchorPosition->x, $anchorPosition->y);

        wm_debug("OFFSET $this -> $now\n");
        $this->setPosition($now);
        $this->relative_resolved = true;

        return true;
    }

    public function getPosition()
    {
        return new WMPoint($this->x, $this->y);
    }

    public function setPosition($point)
    {
        $this->x = $point->x;
        $this->y = $point->y;
        $this->position = $point;
    }

    public function cleanUp()
    {
        parent::cleanUp();

        if (isset($this->image)) {
            imagedestroy($this->image);
        }
        $this->owner = null;
     //   $this->parent = null;
        $this->descendents = null;
        $this->image = null;
    }

    public function getValue($name)
    {
        wm_debug("Fetching %s\n", $name);
        if (property_exists($this, $name)) {
            return $this->$name;
        }
        throw new WeathermapInternalFail("NoSuchProperty");
    }


    private function getDirectionList()
    {
        if ($this->scalevar == 'in') {
            return array(IN);
        }

        return array(OUT);
    }

    function getTemplateObject()
    {
        return $this->owner->getNode($this->template);
    }

    /**
     * @param $map
     * @param $labelColour
     * @return resource
     */
    private function drawArtificialIcon(&$map, $labelColour)
    {
        wm_debug("Artificial Icon type " . $this->iconfile . " for $this->name\n");
        // this is an artificial icon - we don't load a file for it

        $icon_im = imagecreatetruecolor($this->iconscalew, $this->iconscaleh);
        imagesavealpha($icon_im, TRUE);

        $nothing = imagecolorallocatealpha($icon_im, 128, 0, 0, 127);
        imagefill($icon_im, 0, 0, $nothing);

        $fill = NULL;
        $ink = NULL;

        $aifill = $this->aiconfillcolour;
        $aiink = $this->aiconoutlinecolour;

        // if useiconscale isn't set, then use the static
        // colour defined, or copy the colour from the label
        if ($this->useiconscale == "none") {

            if ($aifill->isCopy() && !$labelColour->isNone()) {
                $fill = $labelColour;
            } else {
                if ($aifill->isRealColour()) {
                    $fill = $aifill;
                }
            }
        } else {
            // if useiconscale IS defined, use that to figure out
            // the fill colour
            $pc = 0;
            $val = 0;

            if ($this->iconscalevar == 'in') {
                $pc = $this->percentUsages[IN];
                $val = $this->absoluteUsages[IN];
            }

            if ($this->iconscalevar == 'out') {
                $pc = $this->percentUsages[OUT];
                $val = $this->absoluteUsages[OUT];
            }

            if ($this->iconscaletype == 'percent') {
                list($fill, $junk, $junk) =
                    $this->owner->scales[$this->useiconscale]->ColourFromValue($pc, $this->name);

            } else {
                // use the absolute value if we aren't doing percentage scales.
                list($fill, $junk, $junk) =
                    $this->owner->scales[$this->useiconscale]->ColourFromValue($val, $this->name, false);
            }
        }

        if ($this->aiconoutlinecolour != array(-1, -1, -1)) {
            $ink = $aiink;
        }
//********************************

        wm_debug("ink is: $ink\n");
        wm_debug("fill is: $fill\n");

        if ($this->iconfile == 'box') {
            if ($fill !== NULL && !$fill->isNone()) {
                imagefilledrectangle($icon_im, 0, 0, $this->iconscalew - 1, $this->iconscaleh - 1, $fill->gdallocate($icon_im));
            }

            if ($ink !== NULL && !$ink->isNone()) {
                imagerectangle($icon_im, 0, 0, $this->iconscalew - 1, $this->iconscaleh - 1, $ink->gdallocate($icon_im));
            }
        }

        if ($this->iconfile == 'rbox') {
            if ($fill !== NULL && !$fill->isNone()) {
                imagefilledroundedrectangle($icon_im, 0, 0, $this->iconscalew - 1, $this->iconscaleh - 1, 4, $fill->gdallocate($icon_im));
            }

            if ($ink !== NULL && !$ink->isNone()) {
                imageroundedrectangle($icon_im, 0, 0, $this->iconscalew - 1, $this->iconscaleh - 1, 4, $ink->gdallocate($icon_im));
            }
        }

        if ($this->iconfile == 'round') {
            $rx = $this->iconscalew / 2 - 1;
            $ry = $this->iconscaleh / 2 - 1;

            if ($fill !== NULL && !$fill->isNone()) {
                imagefilledellipse($icon_im, $rx, $ry, $rx * 2, $ry * 2, $fill->gdallocate($icon_im));
            }

            if ($ink !== NULL && !$ink->isNone()) {
                imageellipse($icon_im, $rx, $ry, $rx * 2, $ry * 2, $ink->gdallocate($icon_im));
            }
        }

        if ($this->iconfile == 'nink') {
            $rx = $this->iconscalew / 2 - 1;
            $ry = $this->iconscaleh / 2 - 1;
            $size = $this->iconscalew;
            $quarter = $size / 4;

            $col1 = $this->colours[OUT];
            $col2 = $this->colours[IN];

            assert('!is_null($col1)');
            assert('!is_null($col2)');

            imagefilledarc($icon_im, $rx - 1, $ry, $size, $size, 270, 90, $col1->gdallocate($icon_im), IMG_ARC_PIE);
            imagefilledarc($icon_im, $rx + 1, $ry, $size, $size, 90, 270, $col2->gdallocate($icon_im), IMG_ARC_PIE);

            imagefilledarc($icon_im, $rx - 1, $ry + $quarter, $quarter * 2, $quarter * 2, 0, 360, $col1->gdallocate($icon_im), IMG_ARC_PIE);
            imagefilledarc($icon_im, $rx + 1, $ry - $quarter, $quarter * 2, $quarter * 2, 0, 360, $col2->gdallocate($icon_im), IMG_ARC_PIE);

            if ($ink !== NULL && !$ink->isNone()) {
                // XXX - need a font definition from somewhere for NINK text
                $font = 1;

                $instr = $map->ProcessString("{node:this:bandwidth_in:%.1k}", $this);
                $outstr = $map->ProcessString("{node:this:bandwidth_out:%.1k}", $this);

                $fontObject = $this->owner->fonts->getFont($font);
                list($twid, $thgt) = $fontObject->calculateImageStringSize($instr);
                $fontObject->drawImageString($icon_im, $rx - $twid / 2, $ry - $quarter + ($thgt / 2), $instr, $ink->gdallocate($icon_im));

                list($twid, $thgt) = $fontObject->calculateImageStringSize($outstr);
                $fontObject->drawImageString($icon_im, $rx - $twid / 2, $ry + $quarter + ($thgt / 2), $outstr, $ink->gdallocate($icon_im));

                imageellipse($icon_im, $rx, $ry, $rx * 2, $ry * 2, $ink->gdallocate($icon_im));
            }
        }

        // XXX - needs proper colours
        if ($this->iconfile == 'inpie' || $this->iconfile == 'outpie') {

            $segment_angle = 0;
            if ($this->iconfile == 'inpie') {
                $segment_angle = (($this->percentUsages[IN]) / 100) * 360;
            }
            if ($this->iconfile == 'outpie') {
                $segment_angle = (($this->percentUsages[OUT]) / 100) * 360;
            }

            $rx = $this->iconscalew / 2 - 1;
            $ry = $this->iconscaleh / 2 - 1;

            if ($fill !== null && !$fill->isNone()) {
                imagefilledellipse($icon_im, $rx, $ry, $rx * 2, $ry * 2, $fill->gdallocate($icon_im));
            }

            if ($ink !== null && !$ink->isNone()) {
                imagefilledarc($icon_im, $rx, $ry, $rx * 2, $ry * 2, 0, $segment_angle,
                    $ink->gdallocate($icon_im), IMG_ARC_PIE);
            }

            if ($fill !== null && !$fill->isNone()) {
                imageellipse($icon_im, $rx, $ry, $rx * 2, $ry * 2, $fill->gdallocate($icon_im));
            }
        }

        if ($this->iconfile == 'gauge') {
            wm_warn('gauge AICON not implemented yet [WMWARN99]');
        }

        return $icon_im;
    }

    /**
     * @param $map
     * @param $colicon
     * @return resource
     */
    private function drawRealIcon(&$map, $colicon)
    {
        $this->iconfile = $map->ProcessString($this->iconfile, $this);

        wm_debug("Actual image-based icon from " . $this->iconfile . " for $this->name\n");

        $icon_im = null;

        if (is_readable($this->iconfile)) {
            // draw the supplied icon, instead of the labelled box
            if (isset($colicon)) {
                $colour_method = "imagecolorize";
                if (function_exists("imagefilter") && $map->get_hint("use_imagefilter") == 1) {
                    $colour_method = "imagefilter";
                }

                $icon_im = $this->owner->imagecache->imagecreatescaledcolourizedfromfile(
                    $this->iconfile,
                    $this->iconscalew,
                    $this->iconscaleh,
                    $colicon,
                    $colour_method);

            } else {
                $icon_im = $this->owner->imagecache->imagecreatescaledfromfile(
                    $this->iconfile,
                    $this->iconscalew,
                    $this->iconscaleh);
            }

            if (!$icon_im) {
                wm_warn("Couldn't open ICON: '" . $this->iconfile . "' - is it a PNG, JPEG or GIF? [WMWARN37]\n");
            }

        } else {
            if ($this->iconfile != 'none') {
                wm_warn("ICON '" . $this->iconfile . "' does not exist, or is not readable. Check path and permissions. [WMARN38]\n");
            }
        }
        return $icon_im;
    }

}

;

// vim:ts=4:sw=4:
