<?php
namespace Azine\GeoBlockingBundle\Adapter;

use GeoIp2\Database\Reader;

use Symfony\Component\DependencyInjection\ContainerInterface;

class Maxmind2LookupAdapter implements GeoIpLookupAdapterInterface
{
    protected $geoip = null;

    public function __construct(ContainerInterface $container, $filePath)
    {
        $this->geoip = new Reader($filePath);
    }

    public function getCountry($visitorAddress)
    {
        $record = $this->geoip->city($visitorAddress);

        return $record->country->isoCode;
    }
}
