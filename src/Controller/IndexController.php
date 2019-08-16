<?php
namespace Casebox\CoreBundle\Controller;

use Casebox\CoreBundle\Entity\UsersGroups;
use Casebox\CoreBundle\Service\Auth\CaseboxAuth;
use Casebox\CoreBundle\Service\Browser;
use Casebox\CoreBundle\Service\BrowserView;
use Casebox\CoreBundle\Service\Cache;
use Casebox\CoreBundle\Service\Config;
use Casebox\CoreBundle\Service\Files;
use Casebox\CoreBundle\Service\User;
use Casebox\CoreBundle\Service\Objects;
use Casebox\CoreBundle\Service\Security;
use Casebox\CoreBundle\Service\Plugins\Export\Instance;
use Casebox\CoreBundle\Traits\TranslatorTrait;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Casebox\CoreBundle\Service\Util;
use Casebox\CoreBundle\Service\Templates\SingletonCollection;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\PhpProcess;
use Firebase\JWT\JWT;


/**
 * Class IndexController
 */
class IndexController extends Controller
{
    use TranslatorTrait;

    /**
     * @Route("/c/{coreName}/", name="app_core", requirements = {"coreName": "[a-z0-9_\-]+"})
     *      * @param Request $request
     * @param string $coreName
     *
     * @return Response
     * @throws \Exception
     */
    public function coreAction(Request $request, $coreName)
    {
        $configService = $this->get('casebox_core.service.config');

        /** @var CaseboxAuth $auth */
        $auth = $this->container->get('casebox_core.service_auth.authentication');

        $tsvAuth = $this->get('session')->get('auth');

        if (!$auth->isLogged(false) || !empty($tsvAuth)) {
            return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
        }

        $colors = User::getColors();
        foreach ($colors as $id => $c) {
            $colors[$id] = '.user-color-'.$id."{background-color: $c}";
        }

        $vars = [
            'projectName' => $configService->getProjectName(),
            'coreName' => $request->attributes->get('coreName'),
            'rtl' => $configService->get('rtl') ? '-rtl' : '',
            'cssUserColors' => '<style>'.implode("\n", $colors).'</style>',
            'styles' => $this->container->get('casebox_core.service.styles_service')->getRendered(),
            'locale' => $request->getLocale(),
        ];

        $this->get('translator')->setLocale($vars['locale']);

        $vars['javascript'] = $this->container->get('casebox_core.service.javascript_service')->getRendered($vars);

        return $this->render('CaseboxCoreBundle::index.html.twig', $vars);
    }

    /**
     * @Route("/c/{coreName}/photo/.png", name="app_core_get_default_user_photo")
     * @Route(
     *     "/c/{coreName}/photo/{userId}.{extension}",
     *     defaults={"extension":"(jpg|png)"},
     *     name="app_core_get_user_photo"
     * )
     * @param Request $request
     * @param string $coreName
     * @param int $userId
     *
     * @return Response
     * @throws \Exception
     */
    public function getUserPhotoAction(Request $request, $coreName, $userId = null)
    {
        $auth = $this->container->get('casebox_core.service_auth.authentication');

        if (!$auth->isLogged(false)) {
            return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
        }

        $photo = $this->container->getParameter('kernel.root_dir').'/../web/css/i/ico/32/user-male.png';

        if (!empty($userId)) {
            $q = (!empty($request->get('32'))) ? $request->get('32') : false;
            $photo = $this->container->get('casebox_core.service.user')->getPhotoFilename($userId, $q);
        }

        return new BinaryFileResponse($photo);
    }

    /**
     * @Route("/c/{coreName}/upload/", name="app_core_file_upload", requirements = {"coreName": "[a-z0-9_\-]+"})
     * @param Request $request
     * @param string $coreName
     *
     * @return Response
     * @throws \Exception
     */
    public function uploadAction(Request $request, $coreName)
    {
        $configService = $this->get('casebox_core.service.config');
        $auth = $this->container->get('casebox_core.service_auth.authentication');

        if (!$auth->isLogged(false)) {
            return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
        }

        $result = [
            'success' => false,
        ];

        if (isset($_SERVER['HTTP_X_FILE_OPTIONS'])) {
            $file = Util\jsonDecode($_SERVER['HTTP_X_FILE_OPTIONS']);
            $file['error'] = UPLOAD_ERR_OK;
            $file['tmp_name'] = tempnam($configService->get('incomming_files_dir'), 'cbup');
            $file['name'] = urldecode($file['name']);

		if (substr($file['type'], 0, 6) !== 'image/' && $file['type'] !== 'application/pdf' && (pathinfo($file['name'])['extension'] !== "docx") && (pathinfo($file['name'])['extension'] !== "doc") && (strrpos($file['type'], "document")=== false)) {
			$result=['success' => false, 'msg' => 'Not an image'.$file['type']];
			$result = json_encode($result);
			return new Response($result, 200, ['Content-Type' => 'application/json', 'charset' => 'UTF-8']);
        }

			
            if (empty($file['content_id'])) {
                Util\bufferedSaveFile('php://input', $file['tmp_name']);
            }

            $_FILES = ['file' => $file];
            $browser = new Browser();

            $result = $browser->saveFile(
                [
                    'pid' => @$file['pid'],
                    'draftPid' => @$file['draftPid'],
                    'response' => @$file['response'],
                ]
            );
        }

        if (is_array($result)) {
            $result = json_encode($result);
        }

        return new Response($result, 200, ['Content-Type' => 'application/json', 'charset' => 'UTF-8']);
    }

	/**
	 * @Route("/c/{coreName}/export", name="app_core_export_upload")
	 * @Route("/c/{coreName}/export/", name="app_core_export_slash")
	 * 
	 * @param Request $request        	
	 * @param string $coreName        	
	 * @param string $id        	
	 * @method ({"GET", "POST"})
	 *        
	 * @return Response
	 * @throws \Exception
	 */
	public function export(Request $request, $coreName) {
        $configService = $this->get ( 'casebox_core.service.config' );
                $container = Cache::get('symfony.container');
        $rootDir = $container->getParameter('kernel.root_dir');
        $response = new \stdClass(); //remove strict error message
        $authHeader = $request->get('jwt');
		if ($request->isMethod ( Request::METHOD_GET ))
		{
	    /*
	     * Look for the 'authorization' header
	     */
    	if ($authHeader) {
            try {
                $decoded = JWT::decode($authHeader, $configService->get('jwt_key'), array($configService->get('jwt_algorithm')));
				//print_r($decoded);  //do something with what we decoded?
				$cmd = 'php '.$rootDir.'/../bin/console'.' '.'ecmrs:database:export '.(!empty($request->get('state'))?' --state='.$request->get('state'):'').(!empty($request->get('county'))?' --county='.$request->get('county'):'').(!empty($request->get('tier'))?' --tier='.$request->get('tier'):'').' --env='.$coreName;
				//echo($cmd);
    	
           		$pid = shell_exec($cmd .' > /dev/null & echo $!');
        		   		
        		$response->message = 'process <'. $cmd . '> started on pid <'.$pid.'>';
            } catch (\Exception $e) {
                /*
                 * the token was not able to be decoded.
                 * this is likely because the signature was not able to be verified (tampered token)
                 */
                header('HTTP/1.0 401 Unauthorized');
                $response->message ='unable to decode';
            } 
        } else
        {
            header('HTTP/1.0 401 Unauthorized');
            $response->message = 'no key provided';
        }
        }
        else {
            /*
             * No token was able to be extracted from the authorization header
             */
            header('HTTP/1.0 400 Bad Request');
            $response->message = 'this service only accepts get requests';
        }
        header('Content-type: application/json');
		echo(json_encode($response));
		exit(0);
    
    }	


	/**
	 * @Route("/c/{coreName}/exportstatus", name="app_core_exportstatus_upload")
	 * @Route("/c/{coreName}/exportstatus/", name="app_core_exportstatus_slash")
	 * 
	 * @param Request $request        	
	 * @param string $coreName        	
	 * @param string $id        	
	 * @method ({"GET", "POST"})
	 *        
	 * @return Response
	 * @throws \Exception
	 */
	public function exportstatus(Request $request, $coreName) {
        $configService = $this->get ( 'casebox_core.service.config' );
                $container = Cache::get('symfony.container');
        $rootDir = $container->getParameter('kernel.root_dir');
        
        $authHeader = $request->get('jwt');
		$response = new \stdClass(); //remove strict error message
		if ($request->isMethod ( Request::METHOD_GET ))
		{
    	if ($authHeader) {
            try {
                $decoded = JWT::decode($authHeader, $configService->get('jwt_key'), array($configService->get('jwt_algorithm')));
				$baseDirectory = !empty($configService->get('export_directory'))?$configService->get('export_directory'):'/home/dstoudt/transfer/';
				shell_exec('cd '.$baseDirectory);
				$directorystructure = shell_exec('cd '.$baseDirectory.';find . -mindepth 1 -type d -exec sh -c \'echo "{} - $(find "{}" -type f | wc -l)" \' \;');
				$processes = shell_exec('ps | ');
				
				$response->directorystructure = $directorystructure;
				$response->processes = shell_exec('ps -ef | grep [e]cmrs:database:export');
				$response->processcount = shell_exec('ps -ef | grep [e]cmrs:database:export | wc -l');				
            } catch (\Exception $e) {
                /*
                 * the token was not able to be decoded.
                 * this is likely because the signature was not able to be verified (tampered token)
                 */
                header('HTTP/1.0 401 Unauthorized');
                $response->message ='unable to decode';
            } 
        } else
        {
            header('HTTP/1.0 401 Unauthorized');
            $response->message = 'no key provided';
        }
        }
        else {
            /*
             * No token was able to be extracted from the authorization header
             */
            header('HTTP/1.0 400 Bad Request');
            $response->message = 'this service only accepts get requests';
        }
        header('Content-type: application/json');
		echo(json_encode($response));
		exit(0);
    
    }	

/**
     * @Route("/d", name="app_core_reports")
	 * @Route("/d/", name="app_core_reports_slash")* 
     * @param Request $request
     *
     * @return Response
     * @throws \Exception
     */
    public function reportsAction(Request $request)
    {
    	
		/*$auth = $this->container->get('casebox_core.service_auth.authentication');
        $user = $auth->isLogged(false);
		
		if (!$user) {
			$vars = [
	            'locale' => $this->container->getParameter('locale')
	        ];
	
	        return $this->render('CaseboxCoreBundle::no-core-found.html.twig', $vars);
		}*/
		
		return $this->redirect('/d/index.html');
    }	


/**
     * @Route("/c/{coreName}/reports/", name="app_core_reports", requirements = {"coreName": "[a-z0-9_\-]+"})
     * @param Request $request
     * @param string $coreName
     * @param string $id
     *
     * @return Response
     * @throws \Exception
     */
    public function coreReportsAction(Request $request, $coreName)
    {
    	
		$auth = $this->container->get('casebox_core.service_auth.authentication');
        $user = $auth->isLogged(false);
		$configService = $this->get('casebox_core.service.config');
		$vars = [
	            'locale' => $this->container->getParameter('locale'),
	            'coreName' => $coreName,
				'projectName' => $configService->getProjectName()
	        ];
		if (!$user) {
			$this->get('session')->set('redirectUrl', 'app_core_reports');
			$this->get('session')->set('redirectId', $id);
			return $this->redirectToRoute('app_core_login', $vars);
		}
		//$configuration = \GuzzleHttp\json_decode($objData['data']['value'], true);
		//$vars['reports'] = $configService->get('Reports');
		$sr = new BrowserView();
		$configService = $this->get('casebox_core.service.config');
		//$configuration = \GuzzleHttp\json_decode($objData['data']['value'], true);
		$vars['reports'] = $configService->get('Reports');
		return $this->render('CaseboxCoreBundle::reports.html.twig', $vars);
    }	
	
/**
     * @Route("/c/{coreName}/report/{id}/", name="app_core_report", requirements = {"coreName": "[a-z0-9_\-]+"})
     * @param Request $request
     * @param string $coreName
     * @param string $id
     *
     * @return Response
     * @throws \Exception
     */
    public function reportAction(Request $request, $coreName, $id)
    {
    	
		$configService = $this->get('casebox_core.service.config');
		$headers = ['Content-Type' => 'application/json', 'charset' => 'UTF-8'];		
		$pdfParam = $request->query->get('pdf');
		$xlsParam = $request->query->get('xls');
		$reportDate = empty($request->query->get('reportDateInput'))?date("Y-m-d", time() - 60 * 60 * 28):substr($request->query->get('reportDateInput'),0,10); //get report running date

		$auth = $this->container->get('casebox_core.service_auth.authentication');
        $user = $auth->isLogged(false);
		
		/* Check if user is logged in */
		if (!$user) {
			$this->get('session')->set('redirectUrl', 'app_core_report');
			$this->get('session')->set('redirectId', $id);
			return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
		}
		
		/* Get reports config */
        $reports = $configService->get('Reports');
        if (empty($id) || (!isset($reports[$id]) && !is_numeric($id))) {
			$result['message'] = $this->trans(('Object_not_found'));

            return new Response(json_encode($result), 200, $headers);
        }
		if (is_numeric($id))
		{
			$obj = Objects::getCachedObject($id);		
			$objData = $obj->getData();
			$reportConfig = \GuzzleHttp\json_decode($objData['data']['value'], true);	
		}
		else {
			$reportConfig = $reports[$id];
		}
		$reportConfig['reportId'] = $id;	
		$reportConfig['core_name'] = $coreName;	
		$reportConfig['reports'] = $reports;
		$class = '\\Casebox\\CoreBundle\\Reports\\'.$reportConfig['reportClass'];
        if (class_exists($class)) {
            $class = new $class($reportConfig); //send the report config in
			//exit;
			if ($pdfParam)
			{
				$class->run()->export($reportConfig['reportClass'].'Pdf')->settings(array(
				    "phantomjs"=>"/usr/bin/phantomjs"
				))->pdf(array(
				    "format"=>"A4",
				    "orientation"=>"portrait"
				))->toBrowser($reportConfig['reportClass'].$reportDate.'.pdf');
				exit(0);
			}
			else if ($xlsParam)
			{
				$class->run()->exportToExcel()->toBrowser($reportConfig['reportClass'].$reportDate.'.xlsx');	
			}			
			else
			{
				//$class->run()->render('CaseboxCoreBundle::reports.html.twig');
				$class->run()->render();
			}
			exit(0);
        }
		$result['message'] = $this->trans(('Object_not_found'));

        return new Response(json_encode($result), 200, $headers);
    }	

	/**
	 * @Route("/c/{coreName}/bulkupload", name="app_core_bulk_upload")
	 * @Route("/c/{coreName}/bulkupload/", name="app_core_bulk_upload_slash")
	 * 
	 * @param Request $request        	
	 * @param string $coreName        	
	 * @param string $id        	
	 * @method ({"GET", "POST"})
	 *        
	 * @return Response
	 * @throws \Exception
	 */
	public function bulkupload(Request $request, $coreName) {
		$configService = $this->get ( 'casebox_core.service.config' );
		$auth = $this->container->get ( 'casebox_core.service_auth.authentication' );
		if (! $auth->isLogged ( false )) {
			return $this->redirectToRoute ( 'app_core_login', [ 
					'coreName' => $coreName 
			] );
		}
		$this->get('translator')->setLocale(isset($vars['locale'])?isset($vars['locale']):'en');
		$templateId = $request->get('templateId');
		$vars = [
				'templateId' => $templateId,
				'projectName' => $configService->getProjectName(),
				'templates' => json_decode($configService->get('bulkupload'),true),
				'coreName' => $request->attributes->get('coreName'),
				'rtl' => $configService->get('rtl') ? '-rtl' : '',
				'styles' => $this->container->get('casebox_core.service.styles_service')->getRendered(),
				'locale' => $request->getLocale(),
				'step' => 1
				];
		$message = '';
		$step = $request->get('step');
		switch ($step) {
           case '1':
				if (empty($templateId)) {
					$this->addFlash('notice', 'Please select a template ID');
				
					return $this->render('CaseboxCoreBundle::bulkupload.html.twig', $vars);
				}
				$csvContent = $request->get('csvContent');
				if (!empty($csvContent))
				{
					$_FILES ['file'] ['name'] = 'csvContent';
					$_FILES ['file'] ['type'] = 'text/csv';
					$file = tmpfile();
					fwrite($file, $csvContent);
					$path = stream_get_meta_data($file)['uri']; // eg: /tmp/phpFx0513a					
					$_FILES ['file'] ['tmp_name'] = stream_get_meta_data($file)['uri'];
				}
					
				// validate whether uploaded file is a csv file
				$csvMimes = array ('text/x-comma-separated-values','text/comma-separated-values','application/octet-stream','application/vnd.ms-excel','application/x-csv',
									'text/x-csv','text/csv','application/csv','application/excel','application/vnd.msexcel','text/plain','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
				if (! empty ( $_FILES ['file'] ['name'] ) && in_array ( $_FILES ['file'] ['type'], $csvMimes )) {
					if (is_uploaded_file ( $_FILES ['file'] ['tmp_name'] ) || !empty($csvContent)) {
						$bulkupload = json_decode($configService->get('bulkupload'),true);
						$uploadTemplate = $bulkupload[$templateId];
						if (isset($uploadTemplate['filepath'])) // path to move file to is set
						{
							$message = 'Success - moved to ' . $uploadTemplate['filepath'].DIRECTORY_SEPARATOR.$_FILES ['file'] ['name'];
				            if(!is_dir($uploadTemplate['filepath']))
				            {
				                mkdir($uploadTemplate['filepath']);
				            }
							move_uploaded_file($_FILES ['file'] ['tmp_name'], $uploadTemplate['filepath'].DIRECTORY_SEPARATOR.$_FILES ['file'] ['name']);
						}
						else {  //parse it as a comma delimited and add it as a template value
							$template = SingletonCollection::getInstance()->getTemplate($templateId);
							$templateData = $template->getData();
							$delimiter = !empty($request->get('isTab')) ? "\t" : ",";
							// Check if there is defaultPid specified in template config
							if (!empty($template)) {
								ini_set('auto_detect_line_endings', true);
								$csvFile = fopen ( $_FILES ['file'] ['tmp_name'], 'r' );
								// get titles
								if (!empty($request->get('hasHeader')))
								{
									$titles = fgetcsv ( $csvFile );		
									foreach($titles as $title => $fieldName){
										$bom = pack('H*','EFBBBF');
										$titles[$title] = preg_replace("/^$bom/", '', $fieldName);
										//Cache::get ( 'symfony.container' )->get ( 'logger' )->error ( 'herenow', ( array ) $titles );
									}																
								}
								// parse data from csv file line by line
								while ( ($line = fgetcsv ( $csvFile,0,$delimiter )) !== FALSE ) {
									$results = [];		
									$id = null;
									foreach ( $line as $k => $value ) {
										if (!isset($titles))
										{
											$titles = $line;
											$count = 1;
											foreach($titles as $title => $fieldName){
												$titles[$title] = 'Column ' . $count++;
											}										
										}
										$results [$titles [$k]] = $value;
									}
									$data[] = [
										'data' => $results 
									];																						
								}
								}
								
								if (!empty($templateData['cfg']['defaultPid'])) {
									$pid = $templateData['cfg']['defaultPid'];
								}
								
								if (isset($uploadTemplate['pid'])) 
								{
									$pid = $uploadTemplate['pid'];
								}
								
								$parent =  Objects::getCachedObject(isset($pid)?$pid:1);		
								if (!isset($parent))
								{
									$pid = 1;
								}
								
								// close opened csv file
								//move_uploaded_file($_FILES ['file'] ['tmp_name'],$_FILES ['file'] ['tmp_name']); //maybe should think about temp variables instead
								fclose ( $csvFile );
								$browser = new Browser(); //getchildobjects
								$pids = $browser->getObjectsForField(['scope' => 1]);
								$vars['header'] = array_filter($titles);
								$vars['data'] = $data;
								$vars['pid'] = isset($pid)?$pid:1;
								$vars['parentName'] = (isset($parent))?$parent->getHtmlSafeName():'';
								$vars['templateFields'] = $templateData['fields'];
								$vars['templateFields'][] = ["name"=>"id","title"=>"<<OBJECT ID>>"];
								$vars['pids'] = $pids;
								$vars['templateId'] = $templateId;
								$vars['step'] = 2;
								$_SESSION['csvData'] = $data;
							} //end else of not providing an uploaded file
						} else {
						$message = 'Error uploading';
					}
				} else {
					$this->addFlash('notice', 'Invalid File');
					return $this->render('CaseboxCoreBundle::bulkupload.html.twig', $vars);
				}
			break;	
		case '2':
				$csvData = $_SESSION['csvData'];
				$csvHeaders =$request->get('csvHeader'); 
				$pid =$request->get('pid');
				if (!isset($csvData))
				{
					$vars['step'] = 2;
					break;
				}
				$vars['step'] = 3;
				$template = SingletonCollection::getInstance()->getTemplate($templateId);
				if (!empty($template)) {
					$templateData = $template->getData();
					$requiredFields = $template->getRequiredFields();
					$parent =  Objects::getCachedObject(isset($pid)?$pid:1);		
					$parentName =$parent->getHtmlSafeName();
					
					//* old info */			
					$newObjects = 0;
					$existingObjects = 0;
					
					// parse data from csv file line by line
					$objService = new Objects ();
					//print_r($csvData);		
					foreach ( $csvData as &$line ) {
						$i = 0;
						$oldValue = false;
						$line['message'] = '';
						$templateRequiredFields = $requiredFields;
						foreach ( $line['data'] as $key => $value ) {
							$columnHeader = isset($csvHeaders[$i])?$csvHeaders[$i]:''; 	
							if (empty($columnHeader)) //not mapped
							{
								unset($line['data'][$key]);
								unset($csvHeaders[$i]);
							}
							else 
							{
								if ($key !== $columnHeader)
								{
									if(array_key_exists( $key, $line['data'])) {
										 $keys = array_keys($line['data']);
										 $keys[array_search($key, $keys)] = $columnHeader;
										 $line['data'] = array_combine($keys, $line['data']); 
									 }								
								}		
								if ($columnHeader === "id") {
									$obj = Objects::getTemplateId($value);
									if ($templateData['id'] === $obj)
									{
										$id = $value;
										if (!is_null($id))
										{
											$obj = $objService->load(['id' => $id]);
											$line['old'] = $obj['data']['data'];
											$oldValue = true;
											$line['pid'] = $obj['data']['pid'];
										}
									}
									else
									{
										$line['pid'] = $pid;										
										$line['data'][$key]="";
										$line['message'] = $line['message'] . '&#013;ID was invalid - blanking';											
									}
								}
							}
							$i++;					
						}
						
						$browser = new Browser(); //getchildobjects
						$templateColumnObjects = [];
						foreach ( $csvHeaders as $csvHeader ) {
							$templateColumn = $template->getField($csvHeader);
							if ($templateColumn['type'] == '_objects') {
									if (isset($templateColumn['cfg']['scope']))
									{
										$result = $browser->getObjectsForField(['fieldId' => $templateColumn['id']]);	
										$templateColumnObjects[$templateColumn['id']] = $result['data'];									
									}
				            }
						}
						
						$i = 0;
						$hasError = false;
						foreach ( $line['data'] as $key => $value ) {
							$columnHeader = isset($csvHeaders[$i])?$csvHeaders[$i]:''; 	
							$value = $line['data'][$key]; //line value
							$line['template_id'] = $templateId;
							$line['pid'] = $pid; //set the default one here
							if ($columnHeader !== "id") {
								$templateColumn = $template->getField($columnHeader);
								if ($templateColumn['type'] == '_objects') { //try to see if object is there
									if (isset($templateColumn['cfg']['scope']))
									{
										foreach ( $templateColumnObjects[$templateColumn['id']] as $g => $s ) {
											if (str_replace(' ','',strtolower($value)) == str_replace(' ','',strtolower($s['name'])))
											{
												$line['data'][$key] = $s['id'];
												$value = $s['id'];
											}
										}
										if (!is_numeric($value))
										{
											$line['data'][$key] = '';
											$line['message'] = $line['message'] . '&#013;'. $columnHeader . ' was invalid - blanking';
										}		
									}	
				                }	
								if (empty($value) && isset($templateColumn['cfg']['required']))
								{
									$line['message'] = $line['message'] . '&#013;'.$templateColumn['name'] . ' is blank';
									$hasError=true;		
								}
								else
								{
									$templateRequiredFields = array_diff($templateRequiredFields,array($columnHeader));
									if (isset($templateColumn['cfg']['validationRe']))
									{
										if (!preg_match('/'.$templateColumn['cfg']['validationRe'].'/', $value))
										{
											$line['message'] = $line['message'] . '&#013;'.$templateColumn['name'] . ' does not match regular expression rules' . $templateColumn['cfg']['validationRe'];
											$hasError=true;											
										}
									}									
								}
								//print_r($templateColumn);
							}
							$i++;
						}
						if (sizeof($templateRequiredFields) > 0 && !$oldValue) //not all required items filled
						{
							$hasError = true;
							$line['message'] = $line['message'] . '&#013;Required Fields not set: '.implode($templateRequiredFields,', ');
						}
						$line['isvalid'] = !$hasError && (sizeof($templateRequiredFields) === 0 || $oldValue);	
						$line['isnew'] = !$oldValue;	
					}			
					
					$vars['confirmdata'] = $csvData;
					$vars['templateId'] = $templateId;
					$vars['step'] = 3;
					$vars['confirmheader'] = $csvHeaders;
					$_SESSION['confirmCsvData'] = $csvData;							
					}		
			break;	
		case '3':
			$csvData = $_SESSION['confirmCsvData'];
			$results = [];
			$processFile =$request->get('processFile');
			$updated = 0;
			$created = 0;
			if (empty($processFile))
			{
				$message = 'Not Processing<br>Dump<br>';
			}
			else {
				unset($_SESSION['confirmCsvData']);				
				$message = 'Processing';
			}
			$objService = new Objects ();
			foreach ( $csvData as $k => $result ) 
			{
				if ($result['isvalid'])
				{
					unset($result['isvalid']);
					unset($result['message']);
					unset($result['isnew']);					
					if (isset($result['data']['id']))
					{
						$result['id'] = $result['data']['id'];
						unset ($result['data']['id']);
						if (isset($result['old']))
						{
							$result['data'] = array_merge($result['old'], $result['data']);
							unset($result['old']);	
						}
						$updated++;						
					}
					else
					{
						$created++;
					}
					//print_r($result);
					//exit;
					if (!empty($processFile))
					{
						$newReferral = $objService->save ( [ 
								'data' => $result 
						] );
					}
					else {
						$message = $message . ' <br>' . print_r($result, true);
					}
				}			
			}
			$message = $message . ' <br><br>' . $updated . ' record(s) updated';
			$message = $message . ' <br>' . $created . ' record(s) created';	
			break;
		}
		
    	if ($message)
    	{
    		$this->addFlash('notice', $message);
    	}
    
    	return $this->render('CaseboxCoreBundle::bulkupload.html.twig', $vars);
    }	
	
	/**
	 * @Route("/c/{coreName}/run", name="app_core_command")
	 * @Route("/c/{coreName}/run/", name="app_core_command_slash")
	 * 
	 * @param Request $request        	
	 * @param string $coreName        	
	 * @param string $id        	
	 * @method ({"GET", "POST"})
	 *        
	 * @return Response
	 * @throws \Exception
	 */
	public function run(Request $request, $coreName) {
        $configService = $this->get ( 'casebox_core.service.config' );
        $container = Cache::get('symfony.container');
        $rootDir = $container->getParameter('kernel.root_dir');
        $response = new \stdClass(); //remove strict error message
        $configService = $this->get ( 'casebox_core.service.config' );
		$auth = $this->container->get ( 'casebox_core.service_auth.authentication' );
		if (! $auth->isLogged ( false )) {
			return $this->redirectToRoute ( 'app_core_login', [ 
					'coreName' => $coreName 
			] );
		}

		if (empty($request->get('command'))) {
			echo('Please enter a command');
			exit(0);
		}	

		$cmd = 'php '.$rootDir.'/../bin/console'.' '.$request->get('command');
		
		$params = $request->query->all();
		foreach($params as $key => $val)
		{
			if ($key != 'command' && $key != 'detach')
			{
				$cmd = $cmd . ' --' . $key . '=' . $val;
			}
		}
		$cmd = $cmd . ' --env='.$coreName;
		
		if (!empty($request->get('detach'))) {
			$cmd = $cmd . ' > /dev/null & echo $!';
		}	
		$cmdResponse = shell_exec($cmd);
		
        header('Content-type: application/json');
		echo(json_encode('process <'. $cmd . '> started with response <'.$cmdResponse.'>'));
		exit(0);
    }		
	
    /**
     * @Route("/c/{coreName}/edit/{templateId}/{id}",
     *     name="app_core_item_edit",
     *     requirements = {"coreName": "[a-z0-9_\-]+"},
     *     defaults={"id" = null}
     * )
     * @param Request $request
     * @param string $coreName
     * @param string $id
     *
     * @return Response
     * @throws \Exception
     */
    public function editAction(Request $request, $coreName, $templateId, $id)
    {
        $configService = $this->get('casebox_core.service.config');
        $auth = $this->container->get('casebox_core.service_auth.authentication');

        if (!$auth->isLogged(false)) {
            return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
        }

        $colors = User::getColors();
        foreach ($colors as $id => $c) {
            $colors[$id] = '.user-color-'.$id."{background-color: $c}";
        }

        $vars = [
            'projectName' => $configService->getProjectName(),
            'coreName' => $request->attributes->get('coreName'),
            'rtl' => $configService->get('rtl') ? '-rtl' : '',
            'cssUserColors' => '<style>'.implode("\n", $colors).'</style>',
            'styles' => $this->container->get('casebox_core.service.styles_service')->getRendered(),
            'locale' => $request->getLocale(),
        ];

        $this->get('translator')->setLocale($vars['locale']);

        $vars['javascript'] = $this->container->get('casebox_core.service.javascript_service')->getRendered($vars);

        return $this->render('CaseboxCoreBundle::edit.html.twig', $vars);
    }

    /**
     * @Route("/c/{coreName}/view/{id}/", name="app_core_file_view", requirements = {"coreName": "[a-z0-9_\-]+"})
     * @param Request $request
     * @param string $coreName
     * @param string $id
     *
     * @return Response
     * @throws \Exception
     */
    public function viewAction(Request $request, $coreName, $id)
    {
        $configService = $this->get('casebox_core.service.config');
        $auth = $this->container->get('casebox_core.service_auth.authentication');

        if (!$auth->isLogged(false)) {
            return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
        }

        list($id, $versionId) = explode('_', $id);

        if (!Security::canRead($id)) {
            return new Response($this->trans('Access_denied'));
        }

        $obj = Objects::getCachedObject($id);
        $objData = $obj->getData();
        $objType = $obj->getType();

        switch ($objType) {
            case 'file':
                if (empty($preview)) {
                    $preview = Files::generatePreview($id, $versionId); //$request->get('v')
                }

                $result = '';

                if (is_array($preview)) {
                    if (!empty($preview['processing'])) {
                        $result .= '&#160';

                    } else {
                        $top = '';
                        if (!empty($top)) {
                            $result .= $top.'<hr />';
                        }

                        $filesPreviewDir = $configService->get('files_preview_dir');

                        if (!empty($preview['filename'])) {
                            $fn = $filesPreviewDir.$preview['filename'];
                            if (file_exists($fn)) {
                                $result .= file_get_contents($fn);

                                $dbs = Cache::get('casebox_dbs');
                                $dbs->query('UPDATE file_previews SET ladate = CURRENT_TIMESTAMP WHERE id = $1', $id);
                            }
                        } elseif (!empty($preview['html'])) {
                            $result .= $preview['html'];
                        }
                    }
                }
                break;

            default:
                $preview = array();
                $o = new Objects();
                $pd = $o->getPluginsData(array('id' => $id));
                $title = '';

                if (!empty($pd['data']['objectProperties'])) {
                    $data = $pd['data']['objectProperties']['data'];
                    $title = '<div class="obj-header"><b class="">'.$data['name'].'</div>';
                    $preview = $data['preview'];
                }

                $result = $title.implode("\n", $preview);
                break;
        }

        return new Response($result);
    }

    /**
     * @Route("/c/{coreName}/download/{id}/", name="app_core_file_download", requirements = {"coreName": "[a-z0-9_\-]+"})
     * @param Request $request
     * @param string $coreName
     * @param string $id
     *
     * @return Response
     * @throws \Exception
     */
    public function downloadAction(Request $request, $coreName, $id)
    {
        $result = [
            'success' => false,
        ];

        $headers = ['Content-Type' => 'application/json', 'charset' => 'UTF-8'];

        if (empty($id) || !is_numeric($id)) {
            $result['message'] = $this->trans(('Object_not_found'));

            return new Response(json_encode($result), 200, $headers);
        }

        $versionId = null;
        if (!empty($request->get('v'))) {
            $versionId = $request->get('v');
        }

        // check if public user is given
        $u = $request->get('u');
        if (isset($u) && is_numeric($u)) {
            $userId = $u;
            if (!User::isPublic($userId)) {
                exit(0);
            }
        } else {
            $auth = $this->container->get('casebox_core.service_auth.authentication');
            $user = $auth->isLogged(false);

            if (!$user) {
                return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
            }

            $userId = $user->getId();
        }

        $pw = $request->get('pw');

        Files::download($id, $versionId, !isset($pw), $userId);

        return new Response(null, 200, $headers);
    }
	
	
 /**
     * @Route("/c/{coreName}/get", name="app_core_get", requirements = {"coreName": "[a-z0-9_\-]+"})
	 * @Route("/c/{coreName}/get/", name="app_core_get_slash")
     * @param Request $request
     * @param string $coreName
     * @param string $id
     *
     * @return Response
     * @throws \Exception
     */
    public function exportAction(Request $request, $coreName)
    {
        $result = [
            'success' => false,
        ];
		
		$exportParam = $request->query->get('export');
		$pdfParam = $request->query->get('pdf');
		
		$headers = ['Content-Type' => 'application/json', 'charset' => 'UTF-8'];		
		
        if (empty($exportParam) && empty($pdfParam)) {
			$result['message'] = $this->trans(('Object_not_found'));

            return new Response(json_encode($result), 200, $headers);
        }
		$auth = $this->container->get('casebox_core.service_auth.authentication');
        $user = $auth->isLogged(false);

		if (!$user) {
			return $this->redirectToRoute('app_core_login', ['coreName' => $coreName]);
		}
		
		$export = new Instance();
		
		if (!empty($pdfParam))
		{
			$data = json_decode($pdfParam,true);
	
			$export->getPDF($data);
		}
		else
		{
			$data = json_decode($exportParam,true);
	
			$export->getCSV($data);
		}
		
        return new Response(null, 200, $headers);
    }	
	

    /**
     * @Route("/dav/{coreName}/{action}/{filename}/", name="app_core_file_webdav_slash")
     * @Route("/dav/{coreName}/{action}/{filename}", name="app_core_file_webdav")
     * @Route("/dav/{coreName}/{action}/", name="app_core_action_webdav_slash")
     * @Route("/dav/{coreName}/{action}", name="app_core_action_webdav")
     * @param Request $request
     * @param string $coreName
     * @param string $action
     * @param string $filename
     *
     * @return Response
     * @throws \Exception
     */
    public function webdavAction(Request $request, $coreName, $action = '', $filename = '')
    {
        $r['core'] = $coreName;

        if (!empty($action) && preg_match('/^edit-(\d+)/', $action, $m)) {
            $r['mode'] = 'edit';
            $r['nodeId'] = $m[1];
            $r['editFolder'] = $action;
            $r['rootFolder'] = '/'.$r['editFolder'];

            if (preg_match('/^edit-(\d+)-(\d+)\//', $action, $m)) {
                $r['versionId'] = $m[2];
            }

            if (!empty($filename)) {
                $r['filename'] = $filename;
            }
        } else {
            $r['mode'] = 'browse';
            $r['rootFolder'] = '';
        }

        if (empty($this->get('session')->get('user'))) {
            $user = Cache::get('symfony.container')->get('casebox_core.service.user')->getUserData();
            if (!empty($user['id'])) {
                $this->get('session')->set('user', $user);
                $this->get('session')->save();
            }
        }

        //$log = $this->get('logger');
        //$log->pushHandler($this->get('monolog.handler.nested'));
        //$log->addInfo('$action', [$action]);
        //$log->addInfo('$filename', [$filename]);
        //$log->addInfo('$r', $r);

        //$_GET['core'] = $r['core'];

        $this->get('casebox_core.service.web_dav_service')->serve($r);

        return new Response('');
    }

    /**
     * @Route("/", name="app_default")
     */
    public function indexAction()
    {
        $vars = [
            'locale' => $this->container->getParameter('locale'),
        ];

        return $this->render('CaseboxCoreBundle::no-core-found.html.twig', $vars);
    }
}
