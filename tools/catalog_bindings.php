<?php
/** AChBot pure catalog-binding source proof; never execute application bootstrap. */
function catalogBindingValues(string $source,string $loaderSuffix): array {
    $tokens=token_get_all($source,TOKEN_PARSE);$items=[];
    foreach ($tokens as $token) {
        if (is_array($token) && in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_OPEN_TAG,T_CLOSE_TAG],true)) continue;
        $items[]=is_array($token)?[$token[0],$token[1]]:[null,$token];
    }
    $brace=0;$paren=0;$square=0;$loader=null;$found=[];
    foreach ($items as $index=>[$kind,$value]) {
        $top=$brace===0&&$paren===0&&$square===0;
        if ($top && ($kind===T_REQUIRE_ONCE || ($kind===T_VARIABLE &&
            in_array($value,['$mainValues','$buttonValues'],true) &&
            ($items[$index+1][1]??null)==='='))) {
            $statement=[];$end=$index;
            while (isset($items[$end])) {
                $statement[]=$items[$end][1];
                if ($items[$end++][1]===';') break;
            }
            if ($kind===T_REQUIRE_ONCE) {
                if ($statement===['require_once','__DIR__','.',"'".$loaderSuffix."'",';'] ||
                    $statement===['require_once','__DIR__','.',json_encode($loaderSuffix),';']) $loader=$index;
            } elseif (!isset($found[$value])) {
                $topic=$value==='$mainValues'?'legacy_messages':'legacy_buttons';
                $valid=false;
                foreach (['achbotMessages','\\achbotMessages'] as $call)
                    foreach (["'".$topic."'",json_encode($topic)] as $literal)
                        if ($statement===[$value,'=',$call,'(',$literal,')',';']) $valid=true;
                if (!$valid || $loader===null || $loader>=$index)
                    throw new RuntimeException('canonical_catalog_binding_not_declarative:'.$value);
                // Only the validated two-variable, fixed-topic call expressions
                // execute here; no private include, DB, HTTP or application code.
                $found[$value]=implode(' ',$statement);
            }
        }
        if ($value==='{') ++$brace;
        elseif ($value==='}') --$brace;
        elseif ($kind===T_CURLY_OPEN || $kind===T_DOLLAR_OPEN_CURLY_BRACES) ++$brace;
        elseif ($value==='(') ++$paren;
        elseif ($value===')') --$paren;
        elseif ($value==='[') ++$square;
        elseif ($value===']') --$square;
    }
    if (array_keys($found)!==['$mainValues','$buttonValues'])
        throw new RuntimeException('canonical_catalog_bindings_missing');
    foreach ($found as $statement) eval($statement);
    return ['main'=>$mainValues,'buttons'=>$buttonValues];
}
function catalogAssertCurrentBindings(string $root): array {
    if (file_exists($root.'/settings/values.php'))
        throw new RuntimeException('retired_values_facade_retained');
    $paths=['config.php','health.php'];
    foreach ($paths as $path) {
        $source=file_get_contents($root.'/'.$path);
        if (str_contains($source,'settings/values.php')) throw new RuntimeException('retired_values_facade_still_consumed');
        $bindings=catalogBindingValues($source,'/messages/loader.php');
        if ($bindings['main']!==achbotMessages('legacy_messages') ||
            $bindings['buttons']!==achbotMessages('legacy_buttons'))
            throw new RuntimeException('canonical_catalog_binding_payload_mismatch:'.$path);
    }
    return ['paths'=>$paths,'entries'=>4,'main'=>achbotMessages('legacy_messages'),
        'buttons'=>achbotMessages('legacy_buttons'),'retired_facade_absent'=>true];
}

/** Boundary controls ensure AST extraction cannot execute application expressions. */
function catalogBindingNegativeControls(): int {
    $canonical=<<<'PHP'
<?php
require_once __DIR__.'/messages/loader.php';
$mainValues=achbotMessages('legacy_messages');
$buttonValues=achbotMessages('legacy_buttons');
PHP;
    $bad=[
        str_replace("$"."buttonValues=achbotMessages('legacy_buttons');",'',$canonical),
        str_replace("achbotMessages('legacy_messages')","file_get_contents('unreachable_private_source')",$canonical),
        "<?php require_once __DIR__.'/messages/loader.php';function hidden(){\n".
            "$"."mainValues=achbotMessages('legacy_messages');\n".
            "$"."buttonValues=achbotMessages('legacy_buttons');\n}",
        str_replace("require_once __DIR__.'/messages/loader.php';",'',$canonical).
            "\nrequire_once __DIR__.'/messages/loader.php';"
    ];
    foreach ($bad as $source) {
        try {catalogBindingValues($source,'/messages/loader.php');}
        catch (RuntimeException $expected) {continue;}
        throw new RuntimeException('canonical_catalog_binding_negative_control_missing');
    }
    return count($bad);
}
