<?php

	
	DEFINE('ROUTES_FILE',		CONF_DIR . '/routes.conf.php' );
	DEFINE('MULTIPART_FILE',	HELPERS_DIR . '/multipart.parser.php' );
	DEFINE('NOT_FOUND_PAGE',	PUBLIC_DIR . '/404.html' );
	
	DEFINE('RESPOND_DISABLED',	0x0 );
	DEFINE('RESPOND_HTML',		0x1 );
	DEFINE('RESPOND_JSON',		0x2 );
	DEFINE('RESPOND_OTHER',		0x3 );
	
	
	class Router {
		
		private static $instance = null;
		
		private $url = null;
		private $cachedRoutes = null;
		private $cachedNamedRoutes = null;
		private $exception = null;
		private $controller = null;
		private $controllerAction = null;
		private $controllerFound = false;
		
		private $responseType = RESPOND_DISABLED;

		const ROUTES_KEY = 'routes';
		const NAMED_ROUTES_KEY = 'named_routes';
	
		private function __clone() { }
		
		private function __construct()
		{
			$this->url = ( isset( $_REQUEST['z_url' ] ) && strlen( $_REQUEST['z_url' ] ) > 0 ) ? $_REQUEST['z_url' ] : "" ;
			
			$cc = CommonCache::getInstance();

			$cache = $cc->get( self::ROUTES_KEY );
			$namedRoutes = $cc->get( self::NAMED_ROUTES_KEY );

			$lastMod = @filemtime(ROUTES_FILE) ;
			
			if( $cache === false || $namedRoutes === false ||
				!is_array( $cache ) || !is_array( $namedRoutes ) ||
				!isset( $cache['version'] ) || $cache['version'] !== $lastMod )
			{
				include_once( ROUTES_FILE );
				
				$cache = array();
				
				$this->buildRoutes( $GLOBALS['routes'] , $lastMod !== false ? $lastMod : 0 , $cache, $namedRoutes );

				$cc->set( self::ROUTES_KEY, $cache );
				$cc->set( self::NAMED_ROUTES_KEY, $namedRoutes );
				
				//var_dump( $cache, $namedRoutes );
			}

			$this->cachedRoutes = $cache ;
			$this->cachedNamedRoutes = $namedRoutes ;
 
		}
		
		public static function getInstance()
		{
			if( is_null( static::$instance ) )
				static::$instance = new Router();
				
			return static::$instance;
		}
		
		private static function getNext(&$i, $arr)
		{
			for( ; $i < count( $arr ); $i++)
			{
				if( strlen( $arr[$i] ) > 0 )
					return $arr[$i++];
			}
			
			return false;
		}
		
		public static function add_key_to_array( $key, &$arr )
		{
			if( is_array( $arr ) && !is_null( $key ) )
			{
				if( !isset( $arr[$key] ) || !is_array( $arr[$key] ) )
					$arr[$key] = array();
			}
		}
		
		public static function hasNext($i, $arr)
		{
			for( ; $i < count( $arr ); $i++)
			{
				if( strlen( $arr[$i] ) > 0 )
					return $i;
			}
			
			return false;
		}
		
		
		public function responseType()
		{
			return $this->responseType;
		}
		
		public function foundController()
		{
			return $this->controllerFound ;
		}
		

		
		/**
		 *
		 *	Build routes
		 *
		 */

		private function buildRoutes($routes, $version, &$cached, &$namedRoutes)
		{
			
			function checkInArray($v, $arr)
			{
				return is_null($arr) || in_array( $v, $arr ) ;
			}
			function verifyName( $name )
			{
				return str_replace( array('#', ':'), array('', ''), $name );
			}
			
			function insertMethod( $controller, $name, $method, $action, &$output )
			{
				if( strlen( $name ) == 0 || !is_array($output) )
					return;
					
				$key = '#' . strtoupper( $method ) ;

				Router::add_key_to_array( $name, $output );
				Router::add_key_to_array( $key, $output[$name] );
			
				$output[$name][$key] = array( 'c' => strtolower( $controller ),
											  'a' => $action 		);
			}

			function build_tmp_named_route($name, &$named_var_count, &$named_key, &$named_resource)
			{
				if( !empty($name) )
				{
					if( $name[0] === ':' )
					{
						$named_var_count++;
						
						$named_resource .= "/%{$named_var_count}";
					}
					else
					{
						$named_key = ( empty( $named_key ) ? "$name" : "{$named_key}_{$name}" );
						$named_resource .= "/{$name}";
					}
				}
			}
			
			function processAtom( $arr, $isResource, $controller, $name, &$output, &$named_array, $named_var_count = 0, $named_key = '', $named_resource = '')
			{
				if( !is_array( $arr ) )
					$arr = array();			
				
				$controller = verifyName( isset( $arr['controller'] ) ? $arr['controller'] : $controller ) ;
				
				if( $isResource )
				{
					$id = ":${name}";
								
					$only = ( isset( $arr['only'] ) && count( $arr['only'] ) > 0 ) ? $arr['only'] : null ;
					
					Router::add_key_to_array( $name, $output );

					$hasNormal = false;
					$hasPlus = false;

					$named_var_count++;
					$named_resource = "{$named_resource}" ;
					$named_resource_plus = "{$named_resource}/%{$named_var_count}" ;
					
					if( checkInArray('index', $only) )
					{
						insertMethod($controller, $name, 'get', 'index', $output);
						$hasNormal = true;
					}
					
					if( checkInArray('create', $only) )
					{
						insertMethod($controller, $name, 'post', 'create', $output);
						$hasNormal = true;
					}
					
					if( $hasNormal )
						$named_array[$named_key] = $named_resource;

					if( checkInArray('new', $only) )
					{
						insertMethod($controller, 'new', 'get', 'mnew', $output[$name]);
						$named_array["{$named_key}_new"] = "{$named_resource_plus}/new";
					}

					if( checkInArray('show', $only) )
					{
						insertMethod($controller, $id, 'get', 'show', $output[$name]);
						$hasPlus = true;
					}
						
					if( checkInArray('update', $only) )
					{
						insertMethod($controller, $id, 'put', 'update', $output[$name]);
						$hasPlus = true;
					}

					if( checkInArray('destroy', $only) )
					{
						insertMethod($controller, $id, 'delete', 'destroy', $output[$name]);
						$hasPlus = true;
					}

					if( $hasPlus )
						$named_array["{$named_key}_"] = $named_resource_plus;
						
					if( checkInArray('edit', $only) )
					{
						Router::add_key_to_array( $id, $output[$name] );
							
						insertMethod($controller, 'edit', 'get', 'edit', $output[$name][$id]);
						$named_array["{$named_key}_edit"] = "{$named_resource_plus}/edit";
					}
				}
				else
				{
					if( isset( $arr['action'] ) )
					{
						$via = isset( $arr['via'] ) ? $arr['via'] : 'get' ;
					
						insertMethod($controller, $name, $via, $arr['action'], $output);

						$named_key = ( isset( $arr['as'] ) && $arr['as'] !== '' ) ? $arr['as'] : $named_key ;

						if( !is_null( $named_key ) && $named_key !== false )
						{
							build_tmp_named_route($name, $named_var_count, $named_key, $named_resource );
							$named_array[$named_key] = $named_resource;
						}
					}
				}
				
			}
			
			function processResources($arr, &$output, &$named_array, $named_var_count = 0, $named_key = '', $named_resource = '' )
			{
				if( !is_array( $arr ) )
					return;

				$named_var_count_plus = $named_var_count + 1 ;
				
				foreach( $arr as $key => $val )
				{
					if( $key[0] == ':' )
					{
						$name = verifyName( substr($key, 1) );

						$tmp_named_key = ( empty( $named_key ) ? "$name" : "{$named_key}_{$name}" ) ;
						$tmp_named_resource = "{$named_resource}/{$name}";
						
						processAtom( $val, true, $name, $name, $output, $named_array, $named_var_count, $tmp_named_key, $tmp_named_resource );
						
						Router::add_key_to_array( $name, $output );
							
						if( is_array( $val ) && count( $val ) > 0 )
						{
							Router::add_key_to_array( $key, $output[$name] );

							$tmp_named_resource .= "/%{$named_var_count_plus}";
								
							processResources( $val, $output[$name][$key], $named_array, $named_var_count_plus, $tmp_named_key, $tmp_named_resource );
						}
					
					}
				
				}	
			}
			

			
			/***************************************************************************************************
			 *	Processa recursivamente o namespace e os seus recursos
			 ***************************************************************************************************/
			 
			function processNamespace( $arr, &$output, &$named_array, $named_key = '', $named_resource = '' )
			{
				if( isset( $arr['namespace'] ) && is_array( $arr['namespace'] ) )
				{
					foreach( $arr['namespace'] as $space )
					{
						if( !is_array( $space ) || !isset( $space['name'] ) )
							continue;

						Router::add_key_to_array( $space['name'], $output );

						$tmp_named_key = ( empty( $named_key ) ? "{$space['name']}" : "{$named_key}_{$space['name']}" );
						$tmp_named_resource = "{$named_resource}/{$space['name']}";
						
						processNamespace( $space, $output[ $space['name'] ], $named_array, $tmp_named_key, $tmp_named_resource );
					}
				}
				
				if( isset( $arr['resources'] ) && is_array( $arr['resources'] ) )
					processResources( $arr['resources'], $output, $named_array, 0, $named_key, $named_resource );
					
			}

			function processMatches( $arr, &$output, &$named_array, $named_var_count = 0, $named_key = '', $named_resource = '' )
			{
				if( isset( $arr['matches'] ) && is_array( $arr['matches'] ) )
				{
					foreach( $arr['matches'] as $rule )
					{
						if( !is_array( $rule ) || !isset( $rule['match'] ) )
							continue;

						$controller = null;
						
						$i = 0;
						$val = null;
						$tmp_named_var_count = $named_var_count ;
						$tmp_named_key = "{$named_key}";
						$tmp_named_resource = "{$named_resource}";

						$lastLevel = &$output;
						
						$exp = explode('/', $rule['match'] ) ;

						while( ($next = Router::hasNext( $i, $exp ) ) !== false )
						{
							$i = 1 + $next;
							$val = $exp[$next] ;

							if( is_null( $controller ) )
								$controller = $val;

							Router::add_key_to_array( $val, $lastLevel );

							if( Router::hasNext( $i, $exp ) !== false )
								build_tmp_named_route($val, $tmp_named_var_count, $tmp_named_key, $tmp_named_resource );

							else
								processAtom( $rule, false, $controller, $val, $lastLevel, $named_array, $tmp_named_var_count, $tmp_named_key, $tmp_named_resource );
								

							$lastLevel = &$lastLevel[$val] ;
						}

						if( !is_null( $val ) )
						{
							build_tmp_named_route($val, $tmp_named_var_count, $tmp_named_key, $tmp_named_resource );
							processMatches( $rule, $lastLevel, $named_array, $tmp_named_var_count, $tmp_named_key, $tmp_named_resource );
						}
					}
				}
			}
			
			
			function recursiveArrayClean( &$arr )
			{
				if( !is_array( $arr ) )
					return;
				
				
				foreach( $arr as $k => $v )
				{
					if( is_null( $v ) )
						unset( $arr[ $k ] );
						
					else
					{
						if( is_array( $arr[ $k ] ) )
						{
							if( count( $arr[ $k ] ) > 0 )
								recursiveArrayClean( $arr[ $k ] );
								
							if( count( $arr[ $k ] ) == 0 )
								unset( $arr[ $k ] );
						}
					}
				}
			}
			

			
			/***************************************************************************************************
			 *	Inícia o processo de análise
			 ***************************************************************************************************/
			
			if( !is_array($cached) )
				$cached = array();

				
			$cached['rules'] = array();
			$cached['version'] = $version;
			$cached['root'] = ( isset($routes['root']) && is_string( $routes['root'] ) ) ? $routes['root'] : null;

			$namedRoutes = array();
			
			// Process Namespace / resources
			processNamespace( $routes, $cached['rules'], $namedRoutes );

			// Process Matches
			processMatches( $routes, $cached['rules'], $namedRoutes );

			// Clean possible empty array tails
			recursiveArrayClean( $cached );
			
		}




		private function is_root( $url )
		{
			return strlen( str_replace(array('/', ' ', "\t"), '', $url) ) == 0 ;
		}
		private function is_default_root_valid($where)
		{
			return @(isset( $where['root'] )
						&& is_string( $where['root'] )
						&& ( strlen( $where['root'] ) > 0 )) ;
		}
		
		public function route()
		{
		
			if( $this->is_default_root_valid( $this->cachedRoutes ) && $this->is_root( $this->url ) )
			{
				header('Location: ' . $this->cachedRoutes['root'], true);
			
				return;
			}
			
			
			$i = 0;
			$next = 0;
			$found = false;
			$lastValue = &$this->cachedRoutes['rules'] ;
			
			$exp = explode('/', $this->url) ;

			
			while( ($next = static::hasNext( $i, $exp )) !== false )
			{
				if( !is_array( $lastValue ) )
					break;
				
				$i = 1 + $next;
				$val = $exp[$next];
				
				// Get format
				if( static::hasNext( $i, $exp ) === false )
				{
					$formatExp = explode( '.', $val , 2 );
					$val = $formatExp[0];
					
					$_REQUEST['z_format'] = strtolower( ( count( $formatExp ) > 1 ) ? $formatExp[1] : "html" ) ;
					
					switch( $_REQUEST['z_format'] )
					{
						case 'html':
							$this->responseType = RESPOND_HTML;
							break;
							
						case 'json':
							$this->responseType = RESPOND_JSON;
							break;
							
						default:
							$this->responseType = RESPOND_OTHER;
							break;
					}
				}
				
				$found = false;
				
				// Existe a chave definida
				if( isset( $lastValue[$val] ) )
				{
					$lastValue = &$lastValue[$val];
					$found = true;
				}
				
				// Em alternativa, se existir uma variável, atribui-se o valor
				// à sua primeira ocurrência
				else
				{	
					foreach( $lastValue as $k => $v )
					{
						if($k[0] == ':')
						{
							$lastValue = &$lastValue[$k];
							$found = true;
							
							$_REQUEST[substr($k, 1)] = $val ;
							
							break;
						}
					}
				}
				
				// Se não foi encontrada nenhuma ocorrência
				// então não existe mais alternativas
				if( !$found )
					break;
			}
			
			if( $found )
			{
				$key = '#' . strtoupper( $_SERVER['REQUEST_METHOD'] );
				
				if( isset( $lastValue[ $key ] ) && is_array( $lastValue[ $key ] ) )
				{
					$act = $lastValue[ $key ] ;
					if( isset( $act['a'] ) && isset( $act['c'] ) )
					{
						if( $key === "#PUT" || $key === "#DELETE" )
						{
							require_once(MULTIPART_FILE);

							parse_raw_http_request();
						}

						$this->controller = $act['c'];
						$this->controllerAction = $act['a'];
						$this->method = $_SERVER['REQUEST_METHOD'];
						
						$this->controllerFound = true;
						
					}
				}
			}
			
			if( $this->controllerFound )
				$this->loadController( $this->controller, $this->controllerAction );
				
			else
			{
				header("HTTP/1.0 404 Not Found");
				header('Content-type: text/html; charset=utf-8', true);
				
				include_once( NOT_FOUND_PAGE );
			}
			
			return $this->controllerFound ;
		}
		
		private function loadController( $controller, $action )
		{
			$className = ucfirst($controller) . "Controller";

			$instance = new $className();
			
			if( !method_exists( $instance , $action) )
				throw new Exception("Method '${action}' does not exits in class '${className}'.");
				
			else
				$instance->$action();
		
		}
		
		public function getControllerName()
		{
			return $this->controller;
		}
	
	}
