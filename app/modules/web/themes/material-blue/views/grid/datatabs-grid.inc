<?php
/**
 * @var SP\Html\DataGrid\DataGridTab $data
 * @var ThemeIcons                   $icons
 * @var ConfigData                   $configData
 * @var callable                     $_getvar
 * @var Template                     $this
 */

use SP\Config\ConfigData;
use SP\Core\UI\ThemeIcons;
use SP\Mvc\View\Template;

$tabs = $_getvar('tabs');
?>
<div class="mdl-tabs mdl-js-tabs mdl-js-ripple-effect">
    <div id="tabsHeader" class="mdl-tabs__tab-bar"></div>

    <?php foreach ($tabs as $index => $data): ?>
        <div id="tabs-<?php echo $index; ?>"
             class="mdl-tabs__panel"
             data-tab-route="<?php echo htmlspecialchars($_getvar('tabsRoute'), ENT_QUOTES, 'UTF-8'); ?>"
             data-tab-index="<?php echo $index; ?>">

            <?php include __DIR__ . DIRECTORY_SEPARATOR . 'datagrid-grid.inc'; ?>
        </div>

        <script type="text/javascript">
            sysPassApp.theme.html.tabs.add("#tabsHeader", <?php echo $index; ?>, <?php echo json_encode($data->getTitle(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>, <?php echo $index === $_getvar('activeTab') ? 1 : 0; ?>);
        </script>
    <?php endforeach; ?>
</div>
<!-- End Tabs-->