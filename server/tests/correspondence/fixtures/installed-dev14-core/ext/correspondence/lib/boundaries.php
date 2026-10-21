<?php
declare(strict_types=1);
function correspondenceExecutorFromSnapshot(string $raw,array $catalog):?string
{
    if(strlen($raw)>45000)return null;$json=base64_decode($raw,true);if(!is_string($json)||strlen($json)>32768)return null;
    try{$snapshot=json_decode($json,true,16,JSON_THROW_ON_ERROR);}catch(JsonException){return null;}
    if(!is_array($snapshot)||($snapshot['source']??null)!=='plugin_player_routing_v2'||!is_array($snapshot['present_actors']??null)||!array_is_list($snapshot['present_actors'])||count($snapshot['present_actors'])>256)return null;
    $candidates=[];$nameCounts=[];
    foreach($snapshot['present_actors']as$actor){
        if(!is_array($actor)||!is_int($actor['form_id']??null)||$actor['form_id']<1||$actor['form_id']>4294967295||!is_string($actor['name']??null)||!is_bool($actor['managed']??null)||!is_bool($actor['creature']??null)||(!is_int($actor['distance']??null)&&!is_float($actor['distance']??null))||!is_finite((float)$actor['distance'])||$actor['distance']<0)return null;
        $name=$actor['name'];if($name===''||strlen($name)>256||preg_match('//u',$name)!==1||preg_match('/[\x00-\x1f\x7f|@]/',$name))return null;
        $nameCounts[$name]=($nameCounts[$name]??0)+1;
        if(!$actor['managed']||$actor['creature'])continue;
        $matches=array_values(array_filter($catalog,fn($row)=>($row['npc_name']??null)===$name));
        if(count($matches)!==1||preg_match('/\A(?:0x)?([0-9a-fA-F]{8})\z/D',(string)($matches[0]['refid']??''),$m)!==1||hexdec($m[1])!==$actor['form_id'])continue;
        $candidates[]=$actor;
    }
    usort($candidates,fn($a,$b)=>$a['distance']<=>$b['distance']);
    foreach($candidates as$actor)if($nameCounts[$actor['name']]===1)return $actor['name'];return null;
}
function correspondenceNoteLayout(string $body,int $width,int $height,callable $measure):?array
{
    for($font=12;$font>=8;$font--){
        $lines=[];$y=20;$fits=true;
        foreach(explode("\n",$body)as$paragraph){
            $line='';$wrapped=[];
            foreach(explode(' ',$paragraph)as$word){$next=$line===''?$word:$line.' '.$word;$box=$measure($font,$next);
                if(max($box[0],$box[2],$box[4],$box[6])-min($box[0],$box[2],$box[4],$box[6])>$width-40&&$line!==''){$wrapped[]=$line;$line=$word;}else$line=$next;
            }$wrapped[]=$line;
            foreach($wrapped as$text){$box=$measure($font,$text);$xs=[$box[0],$box[2],$box[4],$box[6]];$ys=[$box[1],$box[3],$box[5],$box[7]];$baseline=(int)ceil($y-min($ys));
                if(20+min($xs)<0||20+max($xs)>$width-20||$baseline+max($ys)>$height-20){$fits=false;break 2;}
                $lines[]=['text'=>$text,'x'=>20,'y'=>$baseline];$y=$baseline+max($ys)+max(4,$font*.5);
            }$y+=$font*.6;
        }
        if($fits)return ['font_size'=>$font,'lines'=>$lines];
    }return null;
}
function correspondenceNoteFits(string $body,int $width,int $height,callable $measure):bool{return correspondenceNoteLayout($body,$width,$height,$measure)!==null;}
function correspondencePrepareCoreNote(string $root,string $title,string $body):bool
{
    $font=$root.'/data/fonts/GloriaHallelujah-Regular.ttf';$background=$root.'/data/textures/chim.png';
    if(!function_exists('imagettfbbox')||!function_exists('imagecreatefrompng')||!is_file($font)||!is_file($background))throw new DomainException('renderer_dependency_unavailable');
    $size=@getimagesize($background);if(!is_array($size))throw new DomainException('renderer_background_unavailable');
    $layout=correspondenceNoteLayout($body,$size[0],$size[1],fn($s,$t)=>imagettfbbox($s,0,$font,$t));
    if($layout===null)throw new DomainException('renderer_content_overflow');
    $image=@imagecreatefrompng($background);if($image===false)throw new DomainException('renderer_background_unavailable');
    try{
        imagesavealpha($image,true);$color=imagecolorallocate($image,0,0,0);
        foreach($layout['lines']as$line)if($line['text']!==''&&imagettftext($image,$layout['font_size'],0,$line['x'],$line['y'],$color,$font,$line['text'])===false)throw new DomainException('renderer_failed');
        $directory=$root.'/data/books';if(!is_dir($directory)&&!@mkdir($directory,0750,true)&&!is_dir($directory))throw new DomainException('renderer_directory_unavailable');
        $path=$directory.'/'.md5(strtolower(trim($title))).'.png';$temporary=$path.'.'.bin2hex(random_bytes(6)).'.tmp';
        if(!imagepng($image,$temporary)||!@rename($temporary,$path)){if(is_file($temporary))@unlink($temporary);throw new DomainException('renderer_output_unavailable');}
    }finally{imagedestroy($image);}
    return true;
}
