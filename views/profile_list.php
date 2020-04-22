<?php $this->layout('base'); ?>
<?php $this->start('content'); ?>
    <h2><?=$this->e($serverInfo['display_name']); ?></h2>

    <table class="tbl">
        <thead>
            <tr><th>Profile</th><th></th></tr>
        </thead>
        <tbody>
<?php foreach ($profileList as $profile): ?>
            <tr>
                <td><?=$this->e($profile['display_name']); ?></td>
                <td class="right">
                    <form method="post" action="download">
                        <input type="hidden" name="profile_id" value="<?=$this->e($profile['profile_id']); ?>">
                        <fieldset>
                            <button name="action" value="download">Download</button>
                        </fieldset>
                    </form>
                </td>
            </tr>
<?php endforeach; ?>
        </tbody>
    </table>
<?php $this->stop('content');