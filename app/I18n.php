<?php
declare(strict_types=1);
namespace Enoch;
final class I18n {
    public readonly string $locale;
    public readonly string $choice;
    public readonly string $theme;
    public readonly array $messages;
    public function __construct(Config $config){
        $supported=['en','de'];
        $choice=$_COOKIE['enoch_language']??'system';$this->choice=in_array($choice,['system',...$supported],true)?$choice:'system';
        $locale='en';
        if($this->choice!=='system')$locale=$this->choice;
        else {
            $preferred=[];
            foreach(explode(',',$_SERVER['HTTP_ACCEPT_LANGUAGE']??'en') as $part){$pieces=explode(';',trim($part));$lang=strtolower(explode('-',$pieces[0])[0]);$q=isset($pieces[1])?(float)substr(trim($pieces[1]),2):1;if($q>0&&in_array($lang,$supported,true))$preferred[$lang]=max($preferred[$lang]??0,$q);}
            arsort($preferred);$locale=array_key_first($preferred)??'en';
        }
        $this->locale=$locale;
        $theme=$_COOKIE['enoch_theme']??'system';$this->theme=in_array($theme,['system','light','dark'],true)?$theme:'system';
        $file=$config->root.'/config/locales/'.$locale.'.json';
        $this->messages=is_file($file)?json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR):[];
    }
    public function text(string $key):string{return $this->messages[$key]??$key;}
}
