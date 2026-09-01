<?php

declare(strict_types=1);

/** @var array $_ */
?>
<div class="section">
    <h2><?php p($l->t('Project Petty Cash')); ?></h2>
    <p>
        <?php p($l->t('Phase 1 foundation settings. Editable administration is added with master-data management in Phase 2.')); ?>
    </p>

    <table class="grid">
        <tbody>
        <tr>
            <th><?php p($l->t('Business timezone')); ?></th>
            <td><?php p((string)$_['timezone']); ?></td>
        </tr>
        <tr>
            <th><?php p($l->t('Default currency')); ?></th>
            <td><?php p((string)$_['defaultCurrency']); ?></td>
        </tr>
        <tr>
            <th><?php p($l->t('OCR')); ?></th>
            <td><?php p($_['ocrEnabled'] ? $l->t('Enabled') : $l->t('Disabled until OCR phase')); ?></td>
        </tr>
        <tr>
            <th><?php p($l->t('Primary OCR language')); ?></th>
            <td>Persian (fa)</td>
        </tr>
        <tr>
            <th><?php p($l->t('Secondary OCR language')); ?></th>
            <td>English (en)</td>
        </tr>
        </tbody>
    </table>
</div>
