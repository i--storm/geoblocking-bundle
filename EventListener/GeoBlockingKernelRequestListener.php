<?php
namespace Azine\GeoBlockingBundle\EventListener;

use Symfony\Component\DependencyInjection\Container;

use Psr\Log\LoggerInterface;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\User\UserInterface;

use Symfony\Component\HttpKernel\HttpKernelInterface;

use Symfony\Component\HttpKernel\Event\GetResponseEvent;

use Symfony\Bundle\FrameworkBundle\Templating\EngineInterface;

use Azine\GeoBlockingBundle\Adapter\GeoIpLookupAdapterInterface;

use Twig\Environment;

use Symfony\Component\HttpKernel\Event\RequestEvent;

use Geoip2\Exception\AddressNotFoundException;

class GeoBlockingKernelRequestListener
{
    private $configParams;
    private $lookUpAdapter;
    private $templating;
    private $logger;
    private $container;

    /**
     * @param EngineInterface             $templating
     * @param GeoIpLookupAdapterInterface $lookupAdapter
     * @param LoggerInterface             $logger
     * @param Container                   $container
     * @param array                       $parameters
     */
    public function __construct(Environment $templating, GeoIpLookupAdapterInterface $lookupAdapter, LoggerInterface $logger, Container $container,  array $parameters)
    {
        $this->configParams = $parameters;
        $this->lookUpAdapter 	= $lookupAdapter;
        $this->templating = $templating;
        $this->logger = $logger;
        $this->container = $container;
    }

    /**
     * @param GetResponseEvent $event
     */
    public function onKernelRequest(RequestEvent $event)
    {
        // ignore sub-requests
        if ($event->getRequestType() == HttpKernelInterface::SUB_REQUEST) {
            return;
        }

        // check if the blocking is enabled at all
        if (!$this->configParams['enabled']) {
            $this->logger->info("azine_geoblocking_bundle: blocking not enabled");

            return;
        }

        $request = $event->getRequest();
        // check if blocking authenticated users is enabled
        $token = $authenticated = $this->container->get('security.token_storage')->getToken();
        if($token){
            $authenticated = $this->container->get('security.token_storage')->getToken()->getUser() instanceof UserInterface;
        }else{
            $authenticated = false;
        }
        if ($this->configParams['blockAnonOnly'] && $authenticated) {
            $this->logger->info("azine_geoblocking_bundle: allowed logged-in user");

            return;
        }

        // allow access it the "geoblocking_allow_cookie" is set to true
        $alloweByCookie = $this->configParams['allow_by_cookie'];

        if($alloweByCookie && $request->cookies->get($this->configParams['allow_by_cookie_name'], false)){
        	return;
        }

        //$visitorAddress = $request->getClientIp();
        $visitorAddress = $this->getClientIp($request);

        //$this->logger->info("azine_geoblocking_bundle: HEADERS");
        //$this->logger->info(print_r($request->headers->all(), true));

        // check if the visitors IP is a private IP => the request comes from the same subnet as the server or the server it self.
        if ($this->configParams['allowPrivateIPs']) {
            $patternForPrivateIPs = "#(^127\.0\.0\.1)|(^10\.)|(^172\.1[6-9]\.)|(^172\.2[0-9]\.)|(^172\.3[0-1]\.)|(^192\.168\.)#";
            if (preg_match($patternForPrivateIPs, $visitorAddress) == 1) {
                $this->logger->info("azine_geoblocking_bundle: allowed private network");

                return;
            }
        }

        // check if the route is allowed from the current visitors country via whitelists
        $routeName = $request->get('_route');
        $allowedByRouteWhiteList = array_search($routeName, $this->configParams['routeWhitelist'], true) === false;
        if (!$allowedByRouteWhiteList) {
            $this->logger->info("azine_geoblocking_bundle: allowed by routeWhiteList");

            return;
        }
        
        try{
            $country = $this->lookUpAdapter->getCountry($visitorAddress);
        }catch(AddressNotFoundException $e){
            $this->logger->error("azine_geoblocking_bundle: ".$e->getMessage());

            return;
        }
        
        $allowedByCountryWhiteList = array_search($country, $this->configParams['countryWhitelist'], true) === false;
        if (!$allowedByCountryWhiteList) {
            $this->logger->info("azine_geoblocking_bundle: allowed by countryWhiteList");

            return;
        }

        // check if the vistitor is a whitelisted IP
        if ($this->isAllowedByIpWhiteListConfig($visitorAddress)) {
            $this->logger->info("azine_geoblocking_bundle: allowed by ipWhiteList");

            return;
        }

        // check if the visitor is allowed because it's a search-engine crawler of google or msn
        if ($this->isAllowedBecauseIpIsSearchEngingeCrawler($visitorAddress)) {
            $this->logger->info("azine_geoblocking_bundle: allowed by searchEngineConfig");

            return;
        }


        // until here everything that is allowed has been filtered out.
        $useRouteBL = array_key_exists('routeBlacklist', $this->configParams) && !empty($this->configParams['routeBlacklist']);
        $useCountryBL = array_key_exists('countryBlacklist', $this->configParams) && !empty($this->configParams['countryBlacklist']);

        // if neither of the blackLists should be used, deny all remaining requests
        if (!$useRouteBL && !$useCountryBL) {
            $this->logger->warning("azine_geoblocking_bundle: no blackLists defined and the request (Route: $routeName, Country: $country, IP: $visitorAddress) was not allowed by any of the whiteList/positive filters.");
            $this->blockAccess($event, $country);

            return;
        }

        // check if one of the blacklists denies access
        if ($useRouteBL) {
            if (array_search($routeName, $this->configParams['routeBlacklist'], true) !== false) {
                $this->logger->warning("azine_geoblocking_bundle: blocked by routeBL.\n".print_r($this->configParams['routeBlacklist'], true));
                $this->blockAccess($event, $country);
            }
        }

        if ($useCountryBL) {
            if (array_search($country, $this->configParams['countryBlacklist'], true) !== false) {
                $this->logger->warning("azine_geoblocking_bundle: blocked by countryBL\n".print_r($this->configParams['countryBlacklist'], true));
                $this->blockAccess($event, $country);
            }
        }

        // one or both blacklists were defined to be used, but the request was not filtered out => allow the request
        $this->logger->info("azine_geoblocking_bundle: allowed, no denial-rule triggered");

        return;
    }

    /**
     * @param GetResponseEvent $event
     * @param string           $country
     */
    private function blockAccess(RequestEvent $event, $country)
    {
        // render the "sorry you are not allowed here"-page
        $parameters = array('loginRoute' => $this->configParams['loginRoute'], 'country' => $country);
        $new_response = new Response($this->templating->render($this->configParams['blockedPageView'], $parameters), Response::HTTP_FORBIDDEN);
        $event->setResponse($new_response);
        $event->stopPropagation();

        if ($this->configParams['logBlockedRequests']) {
            $request = $event->getRequest();
            $routeName = $request->get('_route');
            $ip = $request->getClientIp();
            $uagent = $_SERVER['HTTP_USER_AGENT'];
            $hostName = gethostbyaddr($ip);
            $this->logger->warning("azine_geoblocking_bundle: Route $routeName was blocked for a user from $country (IP: $ip , HostName $hostName, UAgent: '$uagent'");
        }
    }

    /**
     * @param  string  $ip
     * @return boolean
     */
    private function isAllowedByIpWhiteListConfig($ip)
    {
        if ($this->configParams['ip_whitelist']) {
            foreach ($this->configParams['ip_whitelist'] as $pattern) {
                if ($ip == $pattern || @preg_match($pattern, $ip) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  string  $ip
     * @return boolean
     */
    private function isAllowedBecauseIpIsSearchEngingeCrawler($ip)
    {
        if ($this->configParams['allow_search_bots']) {

            // resolve host name
            $hostName = gethostbyaddr($ip);
            // reverse resolve IP
            $reverseIP = gethostbyname($hostName);


            // chekc if the hostname matches any of the search-engine names.
            $searchEngineDomains = $this->configParams['search_bot_domains'];

            $isSearchEngineDomain = false;

            foreach ($searchEngineDomains as $domain) {

                // if the hostname ends with any of the search-engine-domain names
                if (substr($hostName, - strlen($domain)) === $domain) {

                    // set variable to true and stop the loop
                    $isSearchEngineDomain = true;
                    break;
                }
            }

            // if the IP and reverse resolved IP match and the ip belongs to a search-engine-domain
            if ($ip == $reverseIP && $isSearchEngineDomain) {
                // allow the ip
                return true;
            }
        }

        return false;
    }

    private function isBadIp($ip){
        $bad_ips=array(

        );

        $is_bad_ip=false;

        foreach ($bad_ips as $bad_ip){
            if(strpos($ip,$bad_ip)===0){
                $is_bad_ip=true;
                break;
            }
        }

        if($is_bad_ip){
            return $is_bad_ip;
        }

        $re = '/^((25[0-5]|(2[0-4]|1[0-9]|[1-9]|)[0-9])(\.(?!$)|$)){4}$/';
        if(preg_match($re, $ip)===0){
            $is_bad_ip=true;
        }

        return $is_bad_ip;
    }

    public function getClientIp($request)
    {
        if (!empty($_SERVER['HTTP_CLIENT_IP']) && !$this->isBadIp($_SERVER['HTTP_CLIENT_IP'])) { // check ip from share internet
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']) && !$this->isBadIp($_SERVER['HTTP_X_FORWARDED_FOR'])) { // to check ip is pass from proxy
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['HTTP_X_REAL_IP']) && !$this->isBadIp($_SERVER['HTTP_X_REAL_IP']) ) {
            $ip = $_SERVER['HTTP_X_REAL_IP'];
        }elseif(!empty($_SERVER['REMOTE_ADDR']) && !$this->isBadIp($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }else{
            $ip = $request->getClientIp();
        }

        return $ip;
    }
}
