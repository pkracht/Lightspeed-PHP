<?php
/**
 * Lightspeed high-performance hiphop-php optimized PHP framework
 *
 * Copyright (C) <2012> by <Priit Kallas>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @author Priit Kallas <kallaspriit@gmail.com>
 * @package Lightspeed
 * @subpackage Controller
 */

// Require used classes
require_once LIGHTSPEED_PATH.'/Http/HttpRequest.php';
require_once LIGHTSPEED_PATH.'/Http/HttpResponse.php';
require_once LIGHTSPEED_PATH.'/Bootstrapper/BootstrapperBase.php';
require_once LIGHTSPEED_PATH.'/Router/RouterBase.php';
require_once LIGHTSPEED_PATH.'/Router/RouteBase.php';
require_once LIGHTSPEED_PATH.'/Dispatcher/DispatcherBase.php';
require_once LIGHTSPEED_PATH.'/Dispatcher/DispatchToken.php';

/**
 * Thrown if a requested controller could not be found
 */
class InvalidControllerException extends Exception {};

/**
 * Thrown if a controller action does not exist or is not callable.
 */
class InvalidControllerActionException extends Exception {};

/**
 * Base implementation of the front controller.
 *
 * All requests are passed through the front controller that can apply security
 * policies, choose different controller etc. The controller generated response
 * also passes it before being sent back as http response.
 *
 * @author Priit Kallas <kallaspriit@gmail.com>
 * @package Lightspeed
 * @subpackage Controller
 */
class FrontControllerBase {

	/**
	 * This method is called before the front controller dispatches the request
	 * to the actual action controller.
	 *
	 * If this returns false, the dispatching to actual controller action method
	 * is skipped but {@see ControllerBase::onPostDispatch()} is still called
	 * which may return a new {@see DispatchToken} thus forwarding the entire
	 * request to another controller action.
	 *
	 * @param FrontControllerBase $frontController Triggering front-controller
	 * @param HttpRequest $request The initial request
	 * @param BootstrapperBase $bootstrapper Application bootstrapper
	 * @param RouterBase $router The router used to route the request
	 * @param DispatcherBase $dispatcher The dispatcher used for the request
	 * @param RouteBase $route Initially matched route
	 * @param DispatchToken $dispatchToken Dispatch token that led to this
	 * @param HttpResponse $response Response that is may modify
	 * @return boolean Should the request be dispatched
	 */
	public function onPreDispatch(
		FrontControllerBase $frontController,
		HttpRequest $request,
		BootstrapperBase $bootstrapper,
		RouterBase $router,
		DispatcherBase $dispatcher,
		RouteBase $route,
		DispatchToken $dispatchToken,
		HttpResponse $response
	) {
		return true;
	}

	/**
	 * This method is called after actually dispatching a token.
	 *
	 * It may choose to return a dispatch token different from the one the
	 * actual action controller requested.
	 *
	 * @param DispatchToken|null Dispatch token requested by action controller
	 * @return DispatchToken|null New dispatch token to forward request or null
	 */
	public function onPostDispatch(DispatchToken $dispatchToken = null) {
		return $dispatchToken;
	}

	/**
	 * Dispatches a request to an actual controller action method.
	 *
	 * The controller gets all the information gathered so far about the request
	 * including the front controller itself, the request, bootstrapper, matched
	 * route and the dispatch token.
	 *
	 * @param HttpRequest $request The initial HTTP request
	 * @param BootstrapperBase $bootstrapper The bootstrapper used
	 * @param RouterBase $router The router used to route the request
	 * @param DispatcherBase $dispatcher The dispatcher used for the request
	 * @param RouteBase $route The initial route that was matched
	 * @param DispatchToken $dispatchToken The dispatch token leading to it
	 * @return HttpResponse Response generated by the controllers
	 * @throws InvalidControllerException If requested controller is not found
	 * @throws InvalidControllerActionException If action not callable
	 * @throws Exception On application error
	 */
	public function dispatch(
		HttpRequest $request,
		BootstrapperBase $bootstrapper,
		RouterBase $router,
		DispatcherBase $dispatcher,
		RouteBase $route,
		DispatchToken $dispatchToken
	) {
		// Create response that is same for entire dispatch loop
		$response = new HttpResponse();

		// Enter the dispatch loop
		while ($dispatchToken !== null) {
			// If the pre-dispatch of front controller returns false,
			// do not dispatch to action controller and hit the post-dispatch
			// method of front controller that may choose to forward request
			if ($this->onPreDispatch(
					$this,
					$request,
					$bootstrapper,
					$router,
					$dispatcher,
					$route,
					$dispatchToken,
					$response
				)
			) {
				// Find controller and action
				$controllerClassName = $dispatchToken->getControllerClassName();
				$actionMethodName = $dispatchToken->getActionMethodName();

				// Create an instance of the controller
				$controllerClass = self::createControllerInstance(
					$dispatchToken
				);

				// Throw an exception if action method is not callable
				if (!is_callable(
					array($controllerClassName, $actionMethodName)
				)) {
					throw new InvalidControllerActionException(
						'Controller action '.$controllerClassName.
						'::'.$actionMethodName
					);
				}

				// Call the pre-dispatch on the controller, skip calling the
				// action if this returns false
				if ($controllerClass->onPreDispatch(
						$this,
						$request,
						$bootstrapper,
						$router,
						$dispatcher,
						$route,
						$dispatchToken,
						$response
					) !== false
				) {
					call_user_func_array(
						array($controllerClass, $actionMethodName),
						array($dispatchToken->getParams())
					);
				}

				// Call the post-dispatch, even if action was cancelled
				$dispatchToken = $controllerClass->onPostDispatch();
			} else {
				// unset it or we'd go into infinite loop (thanks unit-testing)
				$dispatchToken = null;
			}

			// Front controller may override action controller dispatch token
			$dispatchToken = $this->onPostDispatch($dispatchToken);
		}

		// Apply any kind of post-processing filtering on the response
		$this->filterResponse($response);

		return $response;
	}

	/**
	 * This method may choose to do some sort of post-processing on the response.
	 *
	 * Does nothing by default but you may override this method in your own
	 * implementation to do something fancy with it.
	 *
	 * @param HttpResponse $response The response to work on
	 */
	public function filterResponse(HttpResponse $response) {}

	/**
	 * Tries to solve given request directly to a controller action without
	 * there actually being such a route defined. For that, it is assumed the
	 * first route param is controller name, second action name and the rest
	 * are parameters.
	 *
	 * @param HttpRequest $request The request to consult
	 * @param DispatcherBase $dispatcher The dispatcher
	 * @return Route|null Direct route to controller action or null if failed
	 */
	public function getDirectRoute(
		HttpRequest $request,
		DispatcherBase $dispatcher
	) {
		$routeParams = $request->getRouteParams();

		$controllerName = null;
		$actionName = null;
		$parameters = array();

		if (count($routeParams) >= 1) {
			$routeParamKeys = array_keys($routeParams);

			$controllerName = $routeParamKeys[0];

			if ($routeParams[$controllerName] == 1) {
				$actionName = 'index';
			} else {
				$actionName = $routeParams[$controllerName];
			}

			$parameters = $routeParams;

			unset($parameters[$controllerName]);
		}

		if ($controllerName !== null) {
			$route = new RouteBase(
				$controllerName, // not really important
				'/'.$controllerName.'/'.$actionName, // not really important either
				$controllerName,
				$actionName
			);

			$route->setParams($parameters);
			
			$dispatchToken = $dispatcher->resolve($route);
			
			if (self::controllerFileExists(
				$dispatchToken->getControllerClassFilename()
			)) {
				return $route;
			}
		}

		return null;
	}

	/**
	 * Creates a controller instance based on dispatch token.
	 *
	 * @param DispatchToken $dispatchToken The dispatch token leading to it
	 * @return Controller Action controller instance
	 * @throws Exception If requested class does not exist
	 */
	public static function createControllerInstance(
		DispatchToken $dispatchToken
	) {
		$requiredFile = $dispatchToken->getControllerClassFilename();
		
		if (!self::controllerFileExists($requiredFile)) {
			throw new InvalidControllerException(
				'Controller file "'.$requiredFile.'" does not exist'
			);
		}
		
		$controllerClassName = $dispatchToken->getControllerClassName();
		$actionMethodName = $dispatchToken->getActionMethodName();

		if (!empty($requiredFile)) {
			require_once $requiredFile;
		}
		
		if (LS_DEBUG && !class_exists($controllerClassName)) {
			throw new Exception(
				'Controller class "'.$controllerClassName.
				'" not found in expected file of "'.$requiredFile.
				'", forgot to rename if after copying?'
			);
		}

		return new $controllerClassName();
	}
	
	/**
	 * Returns whether the file for given controller exists.
	 * 
	 * If system cache is enabled, uses cache to resolve this without file stat.
	 * 
	 * @param string $controllerFilename Name of the controller file
	 */
	public static function controllerFileExists($controllerFilename) {
		//@codeCoverageIgnoreStart
		if (!LS_USE_SYSTEM_CACHE) {
			return file_exists($controllerFilename);
		}
		//@codeCoverageIgnoreEnd
		
		$cacheKey = 'lightspeed.controller-file-exists|'.$controllerFilename;
		$exists = Cache::fetchLocal($cacheKey, false);

		if ($exists === false) {
			$exists = file_exists($controllerFilename) ? 1 : 0;
			
			Cache::storeLocal($cacheKey, $exists, LS_TTL_DISPATCH_RESOLVE);
		}
		
		return $exists == 1 ? true : false;
	}
}