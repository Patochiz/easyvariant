<?php
class ActionsEasyVariant
{
    public $name = 'easyvariant';
    
    public function __construct($db)
    {
        echo '<div style="background:blue;color:white;padding:20px;position:fixed;top:0;right:0;z-index:99999;">🔵 ALT HOOK LOADED</div>';
    }
    
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        echo '<div style="background:cyan;color:black;padding:10px;position:fixed;top:60px;right:0;z-index:99999;">💙 ALT HOOK OK</div>';
        return 0;
    }
}
?>