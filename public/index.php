<?php
declare(strict_types=1);
require is_file(__DIR__.'/_enoch/app/bootstrap.php') ? __DIR__.'/_enoch/app/bootstrap.php' : dirname(__DIR__).'/app/bootstrap.php';
use Enoch\Web;
Web::headers();
$auth->session();
$error='';
$setup=(int)$store->query('SELECT COUNT(*) FROM users')->fetchColumn()===0;
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $auth->csrf($_POST['csrf']??'');
        if(($_POST['action']??'')==='logout'){
            $_SESSION=[];session_regenerate_id(true);header('Location: /');exit;
        }
        if($setup){$auth->setup($_POST['key']??'',$_POST['name']??'',$_POST['password']??'');$setup=false;}
        if($auth->login($_POST['name']??'',$_POST['password']??'')){header('Location: /');exit;}
        $error='Username or password is incorrect.';
    }catch(Throwable $exception){$error=$exception instanceof PDOException?'The account database is unavailable.':$exception->getMessage();}
}
$user=$auth->user();
$technical=$user&&($user['ui_mode']==='technical'||$user['role']==='admin');
$admin=$user&&$user['role']==='admin';
$e=Web::escape(...);
$version='20260923-access-3';
$i18n=new Enoch\I18n($config);$tr=$i18n->text(...);$t=static fn(string $text)=>Web::escape($tr($text));
$roleNames=['admin'=>'Administrator','operator'=>'Team member','viewer'=>'View only'];
?>
<!doctype html>
<html lang="<?=$i18n->locale?>" data-theme="<?=$i18n->theme?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=$t('Enoch · Faithful stewardship')?></title>
    <link rel="icon" href="/assets/favicon.svg" type="image/svg+xml">
    <script src="/assets/preferences.js?v=<?=$version?>"></script>
    <link rel="stylesheet" href="/assets/app.css?v=<?=$version?>">
    <script type="application/json" id="translations"><?=json_encode($i18n->messages,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_HEX_APOS)?></script>
    <?php if($user): ?>
    <meta name="csrf-token" content="<?=$e($_SESSION['csrf'])?>">
    <script src="/assets/app.js?v=<?=$version?>" defer></script>
    <?php endif ?>
</head>
<body data-role="<?=$e($user['role']??'')?>" data-mode="<?=$technical?'technical':'simple'?>" data-read-only="<?=$config->get('APP_READ_ONLY')==='1'?'true':'false'?>">
<?php ob_start(); ?><div class="preferences" aria-label="<?=$t('Display preferences')?>">
<label><?=$t('Language')?><select data-preference="language"><?php foreach(['system'=>'System language','de'=>'Deutsch','en'=>'English'] as $value=>$label): ?><option value="<?=$value?>" <?=$i18n->choice===$value?'selected':''?>><?=$t($label)?></option><?php endforeach ?></select></label>
<label><?=$t('Appearance')?><select data-preference="theme"><?php foreach(['system'=>'System theme','light'=>'Light','dark'=>'Dark'] as $value=>$label): ?><option value="<?=$value?>" <?=$i18n->theme===$value?'selected':''?>><?=$t($label)?></option><?php endforeach ?></select></label>
</div><?php $preferences=ob_get_clean(); ?>
<?php if(!$user): ?>
<main class="login">
    <div class="login-story">
        <a class="brand" href="/"><span class="brand-mark"><?=$t('e')?></span><span>enoch</span><i aria-hidden="true">●</i></a>
        <div><p class="eyebrow"><?=$t('SCHAEFCHENS / A SHARED WORK')?></p><h1><?=$t('Faithful in')?><br><?=$t('the little things.')?></h1><p><?=$t('A place to tend what we have been given.')?><br><?=$t('In service to the body of Christ.')?></p></div>
        <small><?=$t('“Let all things be done with charity.”')?><br><?=$t('1 Corinthians 16:14 · KJV')?></small>
    </div>
    <div class="login-side"><div class="login-preferences"><?=$preferences?></div>
        <form method="post" class="login-form">
            <p class="eyebrow"><?=$t('WELCOME TO ENOCH')?></p>
            <h2><?=$setup?$tr('Set up your workspace'):$tr('Welcome back.')?></h2>
            <p><?=$setup?$tr('Create the first administrator using the setup key from the private .env file.'):$tr('Sign in to care for our shared tools.')?></p>
            <?php if($error): ?><div class="error" role="alert"><?=$t($error)?></div><?php endif ?>
            <input type="hidden" name="csrf" value="<?=$e($_SESSION['csrf'])?>">
            <?php if($setup): ?><label><?=$t('Setup key')?><input type="password" name="key" autocomplete="off" required></label><?php endif ?>
            <label><?=$t('Username')?><input name="name" autocomplete="username" minlength="3" maxlength="80" required autofocus></label>
            <label><?=$t('Password')?><input type="password" name="password" autocomplete="<?=$setup?'new-password':'current-password'?>" <?=$setup?'minlength="12" maxlength="72"':''?> required></label>
            <button class="primary" type="submit"><?=$setup?$tr('Create administrator'):$tr('Sign in')?> <span aria-hidden="true">→</span></button>
            <small><?=$t('One body. Many members. Shared stewardship.')?></small>
        </form>
    </div>
</main>
<?php else: ?>
<div class="shell">
    <aside class="sidebar">
        <a class="brand" href="/"><span class="brand-mark"><?=$t('e')?></span><span>enoch</span><i aria-hidden="true">●</i></a>
        <p class="workspace">SCHAEFCHENS<span><?=$t('A place of stewardship')?></span></p>
        <nav aria-label="<?=$t('Main navigation')?>">
            <button class="nav active" data-page="servers" aria-current="page"><span aria-hidden="true">▦</span><?=$technical?$tr('Overview'):$tr('My services')?></button>
            <?php if($technical): ?><button class="nav" data-page="activity"><span aria-hidden="true">≋</span><?=$t('Activity')?></button><?php endif ?>
            <button class="nav" data-page="team"><span aria-hidden="true">♧</span><?=$admin?$tr('People & access'):$tr('My account')?></button>
        </nav>
        <div class="sidebar-bottom">
            <span class="avatar"><?=$e(strtoupper(substr($user['name'],0,1)))?></span>
            <div><strong><?=$e($user['name'])?></strong><small><?=$technical?$t($roleNames[$user['role']]):$tr('Our fellowship')?></small></div>
            <form method="post"><input type="hidden" name="csrf" value="<?=$e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button class="logout" aria-label="<?=$t('Sign out')?>" title="<?=$t('Sign out')?>">↗</button></form>
        </div>
    </aside>
    <main class="main">
        <header class="topbar"><span>Enoch <span aria-hidden="true">/</span> <strong id="page-label"><?=$technical?$tr('Overview'):$tr('My services')?></strong></span><span id="refresh-label"><?=$t('Checking your services…')?></span></header>
        <div class="content"><div class="display-toolbar"><?=$preferences?></div>
            <div id="notice" class="notice" role="status" hidden></div>
            <section id="page-servers" class="page">
                <div class="page-heading">
                    <div><p class="eyebrow"><?=$technical?$tr('FAITHFUL STEWARDSHIP'):$tr('SERVING ONE ANOTHER')?></p><h1><?=$technical?$tr('Tend what is entrusted.'):$tr('Welcome, ').$e($user['name']).'.'?></h1><p><?=$technical?$tr('Care for the tools that serve our fellowship.'):$tr('Turn on the service you need. Open it when it is ready.')?></p></div>
                    <button id="refresh" class="secondary"><span aria-hidden="true">↻</span> <?=$t('Refresh')?></button>
                </div>
                <?php if($technical): ?>
                <div class="summary">
                    <div><span class="summary-label"><?=$t('SERVERS ONLINE')?></span><strong id="online-count">—</strong></div>
                    <div><span class="summary-label"><?=$t('SAVED SNAPSHOTS')?></span><strong id="snapshot-count">—</strong></div>
                    <div><span class="summary-label"><?=$t('SCHEDULER')?></span><strong class="small" id="cron-status"><?=$t('Checking…')?></strong></div>
                </div>
                <div class="section-heading"><h2><?=$t('Assigned servers')?> <span id="service-count"></span></h2><span><?=$t('Persistent IPs · Restore from snapshot')?></span></div>
                <?php else: ?>
                <div class="simple-guidance"><span aria-hidden="true">✦</span><p><?=$t('These services are shared. Before turning one off, please make sure everyone has finished using it.')?></p></div>
                <h2 class="simple-section-title"><?=$t('Your services')?></h2>
                <?php endif ?>
                <div id="servers" class="server-grid"><div class="loading"><?=$t('Checking your services…')?></div></div>
                <?php if($technical): ?>
                    <?php if($admin): ?><div class="section-heading"><h2><?=$t('Scheduled maintenance')?></h2><span><?=$t('One cron, multiple tasks')?></span></div><div id="tasks"></div><p class="field-help"><?=$t('Schedule this command every minute in Hetzner. The caller returns within five seconds. Keep the command private; it contains the scheduler key.')?></p><button class="secondary" id="copy-cron"><?=$t('Copy cron command')?></button><?php endif ?>
                    <div class="section-heading"><h2><?=$t('Recent operations')?></h2><button class="text-button" data-page="activity"><?=$t('View activity →')?></button></div><div id="jobs"></div>
                    <p class="footnote"><?=$t('Keep this page open while an operation runs. If you leave, the scheduler continues it on its next visit. Saved stops release VMs; snapshots and persistent IPs remain billable.')?></p>
                <?php else: ?>
                    <p class="simple-help"><?=$t('Getting ready can take a few minutes. You can leave this page and come back later.')?></p>
                <?php endif ?>
                <blockquote class="scripture"><?=$t('“Moreover it is required in stewards, that a man be found faithful.”')?><cite><?=$t('1 Corinthians 4:2 · KJV')?></cite></blockquote>
            </section>
            <?php if($technical): ?>
            <section id="page-activity" class="page" hidden>
                <div class="page-heading"><div><p class="eyebrow"><?=$t('THE WORKSPACE LOG')?></p><h1><?=$t('A record of our stewardship.')?></h1><p><?=$t('Operations and progress for the services you can access.')?></p></div></div>
                <div id="audit"></div>
            </section>
            <?php endif ?>
            <section id="page-team" class="page" hidden>
                <div class="page-heading"><div><p class="eyebrow"><?=$admin?$tr('PEOPLE & ACCESS'):$tr('YOUR PLACE IN THE FELLOWSHIP')?></p><h1><?=$admin?$tr('Many members. One body.'):$tr('Your account.')?></h1><p><?=$admin?$tr('Give each person the tools and responsibilities they need.'):$tr('Keep your sign-in details up to date.')?></p></div><?php if($admin): ?><button id="new-user" class="primary"><?=$t('+ Add a teammate')?></button><?php endif ?></div>
                <?php if($admin): ?><div id="users"></div><?php endif ?>
                <div class="account-grid">
                    <div class="panel"><h2><?=$t('Signed in as')?> <?=$e($user['name'])?></h2><p><?=$technical?$tr('Your access is set by a workspace administrator.'):$tr('If you need another service or help with a task, ask a team administrator.')?></p><form method="post"><input type="hidden" name="csrf" value="<?=$e($_SESSION['csrf'])?>"><input type="hidden" name="action" value="logout"><button class="secondary"><?=$t('Sign out ↗')?></button></form></div>
                    <form id="password" class="panel"><h2><?=$t('Change your password')?></h2><label><?=$t('Current password')?><input name="current" type="password" autocomplete="current-password" required></label><label><?=$t('New password')?><input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password" required><span class="field-help"><?=$t('Use at least 12 characters.')?></span></label><button class="secondary"><?=$t('Update password')?></button></form>
                </div>
            </section>
        </div>
    </main>
</div>
<dialog id="confirm" aria-labelledby="confirm-title">
    <form method="dialog"><button class="dialog-close" aria-label="<?=$t('Close')?>">×</button></form>
    <p class="eyebrow"><?=$technical?$tr('SERVER OPERATION'):$tr('CARING FOR OUR SHARED TOOLS')?></p>
    <h2 id="confirm-title"></h2><p id="confirm-text"></p><div class="confirm-note" id="confirm-note"></div><div id="operation-options"></div>
    <div class="dialog-actions"><button id="cancel" class="secondary"><?=$t('Cancel')?></button><button id="proceed" class="primary"><?=$t('Continue')?></button></div>
</dialog>
<dialog id="credentials-dialog" aria-labelledby="credentials-title"><button class="dialog-close" id="close-credentials" aria-label="<?=$t('Close')?>">×</button><h2 id="credentials-title"><?=$t('Service credentials')?></h2><p><?=$t('Keep these details private. Access is recorded.')?></p><form id="credentials-form"><div id="credential-fields"></div><?php if($admin): ?><p class="field-help"><?=$t('These are stored reference details. Editing them does not change the password on the server.')?></p><button class="primary" type="submit"><?=$t('Save details')?></button><?php endif ?></form></dialog>
<?php if($admin): ?>
<dialog id="account-dialog" aria-labelledby="account-title">
    <form id="account-form">
        <button type="button" class="dialog-close" id="close-account" aria-label="<?=$t('Close account settings')?>">×</button>
        <p class="eyebrow"><?=$t('PEOPLE & ACCESS')?></p><h2 id="account-title"><?=$t('Add a teammate')?></h2>
        <p id="account-error" class="error" role="alert" hidden></p>
        <input type="hidden" name="id">
        <div class="form-pair"><label><?=$t('Username')?><input name="name" required minlength="3" maxlength="80" autocomplete="off"></label><label id="new-password-field"><?=$t('Temporary password')?><input name="password" type="password" minlength="12" maxlength="72" autocomplete="new-password"></label></div>
        <label><?=$t('Account role')?><select name="role"><option value="operator"><?=$t('Team member · assigned controls')?></option><option value="viewer"><?=$t('View only · no start or stop controls')?></option><option value="admin"><?=$t('Administrator · all services and accounts')?></option></select></label>
        <fieldset class="view-options"><legend><?=$t('Choose their interface')?></legend><label><input type="radio" name="ui_mode" value="simple" checked><span><strong><?=$t('Simple')?></strong><small><?=$t('Large buttons, plain language and only the services they need.')?></small></span></label><label><input type="radio" name="ui_mode" value="technical"><span><strong><?=$t('Technical')?></strong><small><?=$t('Server details, snapshots, progress and activity.')?></small></span></label></fieldset>
        <p id="admin-access-note" class="confirm-note" hidden><?=$t('Administrators use the technical view and can manage every service and account.')?></p>
        <fieldset id="permission-fields"><legend><?=$t('Assign services and operations')?></legend><p class="field-help"><?=$t('Assign viewing, starting, stopping, advanced options and credentials separately. New services are never assigned automatically.')?></p><div id="permission-grid"></div></fieldset>
        <p class="field-help"><?=$t('Changed permissions take effect on the next request. Work already requested may still finish.')?></p>
        <div class="dialog-actions"><button type="button" id="cancel-account" class="secondary"><?=$t('Cancel')?></button><button id="save-account" class="primary"><?=$t('Create account')?></button></div>
    </form>
</dialog>
<?php endif ?>
<?php endif ?>
</body>
</html>
