<?php

declare(strict_types=1);

namespace OCA\PettyCash\Service;

use OCA\PettyCash\Domain\Exception\ValidationException;

/** Pure Jalali/Gregorian conversion so API validation does not depend on server locale data. */
final class JalaliCalendarService {
    private const BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

    public function __construct(private PersianNumberService $numbers) {}

    public function jalaliToGregorian(string $value): string {
        $value = str_replace('-', '/', $this->numbers->normalize(trim($value)));
        if (!preg_match('/^(\d{3,4})\/(\d{1,2})\/(\d{1,2})$/', $value, $m)) throw new ValidationException('Jalali date must use YYYY/MM/DD.');
        $jy=(int)$m[1];$jm=(int)$m[2];$jd=(int)$m[3];
        $this->validateJalali($jy,$jm,$jd);
        $g=$this->d2g($this->j2d($jy,$jm,$jd));
        return sprintf('%04d-%02d-%02d',$g['gy'],$g['gm'],$g['gd']);
    }

    public function gregorianToJalali(string $value): string {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', trim($value), $m)) throw new ValidationException('Gregorian date must use YYYY-MM-DD.');
        $j=$this->d2j($this->g2d((int)$m[1],(int)$m[2],(int)$m[3]));
        return sprintf('%04d/%02d/%02d',$j['jy'],$j['jm'],$j['jd']);
    }

    private function validateJalali(int $jy,int $jm,int $jd):void {
        if($jy<self::BREAKS[0]||$jy>=self::BREAKS[count(self::BREAKS)-1]||$jm<1||$jm>12||$jd<1) throw new ValidationException('Invalid Jalali date.');
        $max=$jm<=6?31:($jm<=11?30:($this->jalCal($jy)['leap']===0?30:29));
        if($jd>$max) throw new ValidationException('Invalid Jalali day for this month.');
    }

    /** @return array{leap:int,gy:int,march:int} */
    private function jalCal(int $jy):array {
        $breaks=self::BREAKS;$bl=count($breaks);$gy=$jy+621;$leapJ=-14;$jp=$breaks[0];$jump=0;
        if($jy<$jp||$jy>=$breaks[$bl-1]) throw new ValidationException('Jalali year is outside supported range.');
        for($i=1;$i<$bl;$i++){ $jm=$breaks[$i];$jump=$jm-$jp;if($jy<$jm)break;$leapJ+=self::div($jump,33)*8+self::div(self::mod($jump,33),4);$jp=$jm; }
        $n=$jy-$jp;$leapJ+=self::div($n,33)*8+self::div(self::mod($n,33)+3,4);
        if(self::mod($jump,33)===4&&$jump-$n===4)$leapJ++;
        $leapG=self::div($gy,4)-self::div((self::div($gy,100)+1)*3,4)-150;
        $march=20+$leapJ-$leapG;
        if($jump-$n<6)$n=$n-$jump+self::div($jump+4,33)*33;
        $leap=self::mod(self::mod($n+1,33)-1,4);if($leap===-1)$leap=4;
        return ['leap'=>$leap,'gy'=>$gy,'march'=>$march];
    }

    private function j2d(int $jy,int $jm,int $jd):int {$r=$this->jalCal($jy);return $this->g2d($r['gy'],3,$r['march'])+($jm-1)*31-self::div($jm,7)*($jm-7)+$jd-1;}
    /** @return array{jy:int,jm:int,jd:int} */
    private function d2j(int $jdn):array {$g=$this->d2g($jdn);$jy=$g['gy']-621;$r=$this->jalCal($jy);$jdn1f=$this->g2d($g['gy'],3,$r['march']);$k=$jdn-$jdn1f;if($k>=0){if($k<=185){$jm=1+self::div($k,31);$jd=self::mod($k,31)+1;return compact('jy','jm','jd');}$k-=186;}else{$jy--;$k+=179;if($r['leap']===1)$k++;}$jm=7+self::div($k,30);$jd=self::mod($k,30)+1;return compact('jy','jm','jd');}
    private function g2d(int $gy,int $gm,int $gd):int {$d=self::div(($gy+self::div($gm-8,6)+100100)*1461,4)+self::div(153*self::mod($gm+9,12)+2,5)+$gd-34840408;$d=$d-self::div(self::div($gy+100100+self::div($gm-8,6),100)*3,4)+752;return $d;}
    /** @return array{gy:int,gm:int,gd:int} */
    private function d2g(int $jdn):array {$j=4*$jdn+139361631;$j=$j+self::div(self::div(4*$jdn+183187720,146097)*3,4)*4-3908;$i=self::div(self::mod($j,1461),4)*5+308;$gd=self::div(self::mod($i,153),5)+1;$gm=self::mod(self::div($i,153),12)+1;$gy=self::div($j,1461)-100100+self::div(8-$gm,6);return compact('gy','gm','gd');}
    private static function div(int $a,int $b):int{return intdiv($a,$b);}
    private static function mod(int $a,int $b):int{return $a-self::div($a,$b)*$b;}
}
