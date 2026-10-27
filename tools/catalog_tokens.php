<?php
/** Token inventory for the current AChBot text catalog audit. Never executes runtime. */
$files = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$all=[];
foreach ($files as $file) {
    $source=file_get_contents($file); $tokens=token_get_all($source); $offset=0; $line=1;
    for($i=0; $i<count($tokens); $i++) {
        $token=$tokens[$i]; $text=is_array($token)?$token[1]:$token;
        $kind=is_array($token)?token_name($token[0]):$token;
        if ($token==='"') {
            $parts=[]; $raw=$text; $begin=$offset; $beginLine=$line;
            $offset+=strlen($text); $line+=substr_count($text,"\n");
            for($i++; $i<count($tokens); $i++) {
                $part=$tokens[$i]; $body=is_array($part)?$part[1]:$part;
                $raw.=$body; $offset+=strlen($body); $line+=substr_count($body,"\n");
                if($part==='"') break;
                $parts[]=[is_array($part)?token_name($part[0]):$part,$body];
            }
            $all[]=['path'=>$file,'kind'=>'interpolated','start'=>$begin,'end'=>$offset,'line'=>$beginLine,'raw'=>$raw,'parts'=>$parts];
            continue;
        }
        if(in_array($kind,['T_CONSTANT_ENCAPSED_STRING','T_INLINE_HTML'],true)) {
            $all[]=['path'=>$file,'kind'=>$kind,'start'=>$offset,'end'=>$offset+strlen($text),'line'=>$line,'raw'=>$text];
        }
        elseif (($argv[1] ?? '') === '--all') {
            $all[]=['path'=>$file,'kind'=>$kind,'start'=>$offset,'end'=>$offset+strlen($text),'line'=>$line,'raw'=>$text];
        }
        $offset+=strlen($text); $line+=substr_count($text,"\n");
    }
}
echo json_encode($all,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
