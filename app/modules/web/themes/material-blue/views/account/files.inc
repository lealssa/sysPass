<?php
/**
 * @var ThemeIcons     $icons
 * @var callable       $_getvar
 * @var AccountExtData $accountData
 * @var AccountAcl     $accountAcl
 * @var ConfigData     $configData
 */

use SP\Config\ConfigData;
use SP\Core\UI\ThemeIcons;
use SP\DataModel\AccountExtData;
use SP\Services\Account\AccountAcl;

?>

<div id="data-files">
    <div class="data">
        <div id="list-account-files"
             data-action-route="<?php echo htmlspecialchars($_getvar('fileListRoute'), ENT_QUOTES, 'UTF-8'); ?>"
             data-item-id="<?php echo (int)$_getvar('accountId'); ?>"
             data-history="<?php echo (int)$_getvar('accountIsHistory', false); ?>"
             data-delete="<?php echo (int)$accountAcl->isShowDelete(); ?>"
             data-sk="<?php echo htmlspecialchars($_getvar('sk'), ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <?php if ($_getvar('editAction')): ?>
            <div id="box-upload-files">
                <div id="drop-account-files"
                     class="round active-tooltip dropzone"
                     data-files-ext="<?php echo htmlspecialchars($_getvar('filesAllowedExts'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-item-id="<?php echo (int)$_getvar('accountId'); ?>"
                     data-sk="<?php echo htmlspecialchars($_getvar('sk'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-action-route="<?php echo htmlspecialchars($_getvar('fileUploadRoute'), ENT_QUOTES, 'UTF-8'); ?>"
                     data-delete="<?php echo (int)$accountAcl->isShowDelete(); ?>">
                    <i class="material-icons md-60 mdl-color-text--teal-500">cloud_upload</i>
                </div>
                <form method="post" enctype="multipart/form-data"
                      name="upload_form" class="file-upload"
                      id="fileUploadForm">
                    <input type="file" id="inFile" multiple name="inFile"/>
                </form>
                <div class="mdl-tooltip" for="drop-account-files">
                    <?php printf(
                        __('Drop files here (max. 5) or click to select')
                        . '<br><br>'
                        . __('Maximum file size: %d MB'),
                        $_getvar('maxFileSize')); ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
