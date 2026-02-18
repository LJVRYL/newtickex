<?php

require_once dirname(__FILE__) . '/../../../app/constants.php';
require_once dirname(__FILE__) . '/../../../app/bootstrap.php.cache';
require_once dirname(__FILE__) . '/../../../app/AppKernel.php';
require_once dirname(__FILE__) . '/class.highlighter.php';

use Symfony\Component\HttpFoundation\Request;

umask(0000);
$kernel = new AppKernel('prod', false);
$kernel->loadClassCache();
$request = Request::createFromGlobals();
$response = $kernel->handle($request);
$kernel->getContainer()->set('request', $request);

$hl = new Highlighter;

if (!preg_match('/^(127\.|192\.168|10\.)/', @$_SERVER['REMOTE_ADDR'])) {
    header('HTTP/1.0 403 Forbidden');
    die('This script is only accessible from internal');
}

$oEm = $kernel->getContainer()->get('doctrine')->getManager();
$lastLTr = $oEm->getRepository('PanelCommonBundle:LanguageTranslation')->findAll();
//$lastLTo = $oEm->getRepository('PanelCommonBundle:LanguageToken')->findAll();
$lastLTo = $oEm->getRepository('PanelCommonBundle:LanguageToken')->findBy(array(), array('id' => 'ASC'));
$_lastLTr = $lastLTr = array_pop($lastLTr)->getId();
$_lastLTo = $lastLTo = array_pop($lastLTo)->getId();
/** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** ** **/

$lto = (array) $request->request->get('language_token');
$ltrES = $request->request->get('translation_es');
$ltrEN = $request->request->get('translation_en');
$ltrPR = $request->request->get('translation_pr');
$ltrCat = $request->request->get('catalogue');

$result = $resultLTO = $resultLTR = '';
foreach ($lto as $itemKey => $itemValue) {
    if (
        isset($lto[$itemKey]) && $lto[$itemKey] &&
        isset($ltrES[$itemKey]) && $ltrES[$itemKey] &&
        isset($ltrEN[$itemKey]) && $ltrEN[$itemKey] &&
        isset($ltrPR[$itemKey]) && $ltrPR[$itemKey] &&
        isset($ltrCat[$itemKey]) && $ltrCat[$itemKey]
    ) {
        $lastLTr++; $lastLTo++;
        $resultLTO .= sprintf("INSERT INTO `language_token` (`id`, `token`) VALUES (%d, %s);\n",
            $lastLTo, $oEm->getConnection()->quote($lto[$itemKey])
        );
        $resultLTR .= sprintf("INSERT INTO `language_translation` (`catalogue`, `translation`, `idlanguage`, `idlanguagetoken`) VALUES (%s, %s, 1, %d);\n",
            $oEm->getConnection()->quote($ltrCat[$itemKey]), $oEm->getConnection()->quote($ltrES[$itemKey]), $lastLTo
        );

        $resultLTR .= sprintf("INSERT INTO `language_translation` (`catalogue`, `translation`, `idlanguage`, `idlanguagetoken`) VALUES (%s, %s, 2, %d);\n",
            $oEm->getConnection()->quote($ltrCat[$itemKey]), $oEm->getConnection()->quote($ltrEN[$itemKey]), $lastLTo
        );

        $resultLTR .= sprintf("INSERT INTO `language_translation` (`catalogue`, `translation`, `idlanguage`, `idlanguagetoken`) VALUES (%s, %s, 3, %d);\n",
            $oEm->getConnection()->quote($ltrCat[$itemKey]), $oEm->getConnection()->quote($ltrPR[$itemKey]), $lastLTo
        );

        $result = $resultLTO . "\n" . $resultLTR;
    }
}

?>
<link rel="stylesheet" type="text/css" href="/application/assets/built/index.hosting.css?v=" />
<body>
    <div class="container">

        <?php if ($result) { ?>
            <h4>Queries</h4>
            <pre class="mt10" style="font-size: 10px;"><?php echo $hl->highlight($result); ?></pre>
        <?php } ?>

        <form method="post">
            <div class="row-fluid">
                <ul class="unstyled mt10 span12">
                    <li class="well clearfix">
                        <div class="span10">
                            <label>PanelCommonBundle:LanguageTranslation:LAST_ID: <b><?php echo $_lastLTr; ?></b></label>
                            <label>PanelCommonBundle:LanguageToken:LAST_ID: <b><?php echo $_lastLTo; ?></b></label>
                        </div>
                        <div class="span2 right">
                            <input class="btn btn-primary" type="submit" value="Build queries" />
                        </div>
                    </li>
                    <?php foreach(range(1, 25) as $i) { ?>

                    <li class="well">
                        <label>Token: <input type="text" name="language_token[]" class="span9"/></label>
                        <label>Translation ESP: <input type="text" name="translation_es[]" class="span9"/></label>
                        <label>Translation ENG: <input type="text" name="translation_en[]" class="span9"/></label>
                        <label>Translation PR: <input type="text" name="translation_pr[]" class="span9"/></label>
                        <label>Catalogue: <input type="text" name="catalogue[]" value="templates" class="span9"/></label>
                    </li>
                    <?php } ?>
                </ul>
            </div>
            <input class="btn btn-primary align-right" type="submit" value="Build queries" />
        </form>
    </div>
</body>
