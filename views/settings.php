<?php $this->layout('base'); ?>
<?php $this->start('content'); ?>
<p><a href="../">Home</a></p>
    <h2>Settings</h2>
    <form method="post" action="clearList"><button type="submit">Reset App Data</button></form>
<?php $this->stop('content');
