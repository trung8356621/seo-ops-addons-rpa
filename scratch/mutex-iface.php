<?php

require 'D:/work/omnichannel-client/vendor/autoload.php';

foreach ([
    Illuminate\Console\Scheduling\EventMutex::class,
    Illuminate\Console\Scheduling\SchedulingMutex::class,
] as $i) {
    $r = new ReflectionClass($i);
    foreach ($r->getMethods() as $m) {
        $params = [];
        foreach ($m->getParameters() as $p) {
            $params[] = ((string) $p->getType()).' $'.$p->getName();
        }
        echo $i.'::'.$m->getName().'('.implode(', ', $params).'): '.(string) $m->getReturnType().PHP_EOL;
    }
}
