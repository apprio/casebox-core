<?php
namespace Casebox\CoreBundle\Controller;

use Casebox\CoreBundle\Entity\UsersGroups;
use Casebox\CoreBundle\Service\Auth\CaseboxAuth;
use Casebox\CoreBundle\Service\Browser;
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

		if (substr($file['type'], 0, 6) !== 'image/' && $file['type'] !== 'application/pdf' && (strrpos($file['type'], "document")=== false)) {
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
	
	
		/*$key = "test";
		$token = array(
    		"iss" => "http://example.org",
    		"aud" => "http://example.com",
    		"iat" => 1356999524,
    		"nbf" => 1357000000
		);

		$jwt = JWT::encode($token, $key);
		//print_r($decoded);
		echo($jwt);
		*/
        $configService = $this->get ( 'casebox_core.service.config' );
        
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
				$cmd = $rootDir.'/../bin/console'.' '.'ecmrs:database:export '.(!empty($request->get('state'))?' --state='.$request->get('state'):'').(!empty($request->get('county'))?' --county='.$request->get('county'):'').(!empty($request->get('tier'))?' --tier='.$request->get('tier'):'').' --env='.$coreName;
				//echo($cmd);
        		$process = new Process($cmd);
        		$process->run();
        		$response->message = 'process <'. $cmd . '> ran';
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
		
		$this->get('translator')->setLocale($vars['locale']);
		
		$vars = [
				'templateId' => $request->get('templateId'),
				'projectName' => $configService->getProjectName(),
				'templates' => json_decode($configService->get('bulkupload'),true),
				'coreName' => $request->attributes->get('coreName'),
				'message' => $message,
				'rtl' => $configService->get('rtl') ? '-rtl' : '',
				'styles' => $this->container->get('casebox_core.service.styles_service')->getRendered(),
				'locale' => $request->getLocale(),
		];
		$vars['javascript'] = $this->container->get('casebox_core.service.javascript_service')->getRendered($vars);
		$message = '';
		if ($request->isMethod ( Request::METHOD_POST )) {
			
		if (empty($request->get('templateId'))) {
			$this->addFlash('notice', 'Please select a template ID');
		
			return $this->render('CaseboxCoreBundle::bulkupload.html.twig', $vars);
		}	
		
			
			// validate whether uploaded file is a csv file
			$csvMimes = array (
					'text/x-comma-separated-values',
					'text/comma-separated-values',
					'application/octet-stream',
					'application/vnd.ms-excel',
					'application/x-csv',
					'text/x-csv',
					'text/csv',
					'application/csv',
					'application/excel',
					'application/vnd.msexcel',
					'text/plain' 
			);
			if (! empty ( $_FILES ['file'] ['name'] ) && in_array ( $_FILES ['file'] ['type'], $csvMimes )) {
				if (is_uploaded_file ( $_FILES ['file'] ['tmp_name'] )) {
					ini_set('auto_detect_line_endings', true);
					// open uploaded csv file with read only mode
					$csvFile = fopen ( $_FILES ['file'] ['tmp_name'], 'r' );
					
					// get titles
					$titles = fgetcsv ( $csvFile );
					$objService = new Objects ();
					Cache::get ( 'symfony.container' )->get ( 'logger' )->error ( 'suptestssss', (array) $titles);
					$templates = json_decode($configService->get('bulkupload'),true);
					$template = $templates[$request->get('templateId')];
					
					$template = SingletonCollection::getInstance()->getTemplate($request->get('templateId'));
					
					foreach($titles as $title => $fieldName){
						$bom = pack('H*','EFBBBF');
						$titles[$title] = preg_replace("/^$bom/", '', $fieldName);
						//Cache::get ( 'symfony.container' )->get ( 'logger' )->error ( 'herenow', ( array ) $titles );
					}
					
					// Check if there is defaultPid specified in template config
					if (!empty($template)) {
						$templateData = $template->getData();
					
						if (!empty($templateData['cfg']['defaultPid'])) {
							$pid = $templateData['cfg']['defaultPid'];
						}
						
					foreach($titles as $fieldName)
					{
						if (!$template->getField($fieldName) && $fieldName !== "id")
						{
							//Cache::get ( 'symfony.container' )->get ( 'logger' )->error ( 'doenstmatch'.$obj.'and'.$pid, ( array ) $templateData );	
							$this->addFlash('notice', 'Template Field '.$fieldName.' doesnt match any fields in template ' . $templateData['name']);
							
							return $this->render('CaseboxCoreBundle::bulkupload.html.twig', $vars);
						}
						
					}					
					
					// parse data from csv file line by line
					while ( ($line = fgetcsv ( $csvFile )) !== FALSE ) {
						$id = null;
						foreach ( $line as $k => $value ) {
							if ($titles[$k] === "id") {
								$obj = Objects::getTemplateId($value);
								if ($templateData['id'] === $obj)
								{
									$id = $value;
									if (!is_null($id))
									{
										$obj = $objService->load(['id' => $id]);
										$results = array_merge($obj['data']['data'], $results);
										//print_r($r);
									}
								}
							} else {
								$results [$titles [$k]] = $value;
							}
						}
						$data = [
								'id' => !is_null($id)?$id:null,
								'pid' => $pid,
								'title' => 'New Location',
								'template_id' => $templateData['id'],
								'path' => '/Test Event/Locations',
								'view' => 'edit',
								'name' => 'New Location',
								'data' => $results 
						];
						$newReferral = $objService->save ( [ 
								'data' => $data 
						] );
						if ($id < 211431)
						{
							Cache::get ( 'symfony.container' )->get ( 'logger' )->error ( 'heywhathtehell' . $id, $results );
							}
					}
					}
					// close opened csv file
					fclose ( $csvFile );
					$vars['templateId'] = null;
					$message = 'Success';
				} else {
					$message = 'Error uploading';
				}
			} else {
						$this->addFlash('notice', 'Invalid File');
					
						return $this->render('CaseboxCoreBundle::bulkupload.html.twig', $vars);
			}
		}
		
    	if ($message)
    	{
    		$this->addFlash('notice', $message);
    	}
    
    	return $this->render('CaseboxCoreBundle::bulkupload.html.twig', $vars);
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
