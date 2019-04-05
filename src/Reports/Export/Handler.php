<?php
/**
 * This file contains class the handle to file generated
 *
 * @author KoolPHP Inc (support@koolphp.net)
 * @link https://www.koolphp.net
 * @copyright KoolPHP Inc
 * @license https://www.koolreport.com/license#regular-license
 * @license https://www.koolreport.com/license#extended-license
 */

namespace Casebox\CoreBundle\Reports\Export;
use \JonnyW\PhantomJs\Client;
use \koolreport\core\Utility;

class Handler
{
    protected $phantomjs;
    protected $sourceFile;
    protected $report;
    protected $view;
    protected $useLocalTempFolder = false;
    protected $resourceWaiting = 1000;
    
    public function __construct($report,$view)
    {
        $this->report = $report;
        $this->view = $view;
    }

    public function settings($settings)
    {
        $phantomjs = Utility::get($settings,"phantomjs");
        if($phantomjs)
        {
            $this->phantomjs = $phantomjs;
        }
        $resourceWaiting = Utility::get($settings,"resourceWaiting");
        if($resourceWaiting)
        {
            $this->resourceWaiting = $resourceWaiting;
        }
        $this->useLocalTempFolder = Utility::get($settings,"useLocalTempFolder");  
        return $this;      
    }

    protected function runPhantom($script,$source,$output,$params)
    {
        if(!$this->phantomjs)
        {
            $this->phantomjs = realpath(dirname(__FILE__))."/bin/phantomjs";
            if(!is_file($this->phantomjs))
            {
                $this->phantomjs.=".exe";
                if(!is_file($this->phantomjs))
                {
                    throw new \Exception("Could not find phantomjs executed file in bin folder");
                }
            }    
			if(!is_executable($this->phantomjs))
            {
                throw new \Exception("Please set executable permission for phantomjs");    
            }
        }
        $command = $this->phantomjs." --ignore-ssl-errors=true $script $source $output $params";
        $result = shell_exec($command);

        if(strpos($result,";")===false)
        {
            throw new \Exception("Could not execute phantomjs");
        }
        $result = explode(";",$result);
        if($result[0]=="0")
        {
            throw new \Exception($result[1]);
            return false;
        }
        else
        {
            return true;    
        }
    }

    protected function saveTempContent()
    {
        $content = $this->report->render($this->view,true);
        $source = $this->getTempFolder()."/".Utility::getUniqueId().".tmp";
		file_put_contents($source, $content);
		return $source;
        /*if(file_put_contents($source, $content))
        {
            return $source;
        }
        else
        {
            throw new \Exception("Could not save content to temporary folder");
            return false;
        }*/
    }

    protected function getTempFolder()
    {
        if($this->useLocalTempFolder)
        {
            if(!is_dir(realpath(dirname(__FILE__))."/tmp"))
            {
                mkdir(realpath(dirname(__FILE__))."/tmp");
            }
            return realpath(dirname(__FILE__))."/tmp";
        }
        return sys_get_temp_dir();
    }
    
    
    /*
     * params = array(
     *      "height"=>123, //"cm,in",
     *      "width"=>123,
     *      "format"=>"A4",
     *      "orientation"=>"portrait",
     *      "header"=>array(
     *          "height"=>"1cm",
     *          "contents"=>"{pageNum}/{numPages}"
     *      ),
     *      "footer"=>array(
     *          "height"=>"1cm",
     *          "contents"=>"{pageNum}/{numPages}"
     *      ),
     *  )
     */

    protected function getIsSecureConnection()
    {
        return isset($_SERVER['HTTPS']) && (strcasecmp($_SERVER['HTTPS'],'on')===0 || $_SERVER['HTTPS']==1)
            || isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strcasecmp($_SERVER['HTTP_X_FORWARDED_PROTO'],'https')===0;
    }

    protected function getFullUrl()
    {
        $protocol = $this->getIsSecureConnection()?"https":"http";
        $http_host = (!empty($_SERVER['HTTP_HOST']))?"$protocol://".$_SERVER['HTTP_HOST']:"http://localhost";
        $uri = $_SERVER["REQUEST_URI"];
        return $http_host.$uri;
    }

    public function pdf($params=array())
    {
        $script = realpath(dirname(__FILE__))."/pdf/pdf.js";
        $source = $this->saveTempContent();

        $params["expectedLocation"] = $this->getFullUrl();
        $params["resourceWaiting"] = $this->resourceWaiting;
        
        $output = $this->getTempFolder()."/".Utility::getUniqueId().".pdf";
        if($source)
        {
            if($this->runPhantom($script,$source, $output, base64_encode(json_encode($params))))
            {
                return new File($output);
            }
        }
    }
    
    protected function image($type,$params)
    {
        //base on the filename extension to export PNG,JPG,BMP,TIFF
        $script = realpath(dirname(__FILE__))."/image/image.js";
        $source = $this->saveTempContent();
        $useHttp = false;
        if(isset($params["useHttp"]))
        {
            $useHttp = $params["useHttp"];
            unset($params["useHttp"]);
        }
        $params["expectedLocation"] = $this->getFullUrl($useHttp);
        $params["resourceWaiting"] = $this->resourceWaiting;
        
        $output = $this->getTempFolder()."/".Utility::getUniqueId().".$type";
        if($source)
        {
            if($this->runPhantom($script,$source, $output, base64_encode(json_encode($params))))
            {
                return new File($output);
            }
        }        
    }
    /*
     * $params =array(
     *      "width":"1212px",
     *      "height":"1233px",
     * )
     * 
     */         
    public function jpg($params=array())
    {
        return $this->image('jpg',$params);
    }
    public function gif($params=array())
    {
        return $this->image('gif',$params);
    }
    public function bmp($params=array())
    {
        return $this->image('bmp',$params);
    }
    public function ppm($params=array())
    {
        return $this->image('ppm',$params);
    }
    public function png($params=array())
    {
        return $this->image('png',$params);
    }    
}