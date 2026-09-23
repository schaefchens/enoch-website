<?php
declare(strict_types=1);
namespace Enoch;
final class Budget {
    private static ?float $deadline=null;
    public static function start(int $seconds=22):void{self::$deadline=microtime(true)+$seconds;}
    public static function remaining(int $maximum):int {
        $left=self::$deadline===null?$maximum:(int)floor(self::$deadline-microtime(true));
        if($left<1)throw new \RuntimeException('Request time budget reached. Inspect the saved operation state before retrying.');
        return min($maximum,$left);
    }
}
