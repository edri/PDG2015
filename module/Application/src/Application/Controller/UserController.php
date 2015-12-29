<?php
/**
 * Zend Framework (http://framework.zend.com/)
 *
 * @link      http://github.com/zendframework/ZendSkeletonApplication for the canonical source repository
 * @copyright Copyright (c) 2005-2014 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */
// The namespace is important. It avoids us from being forced to call the Zend's methods with
// "Application\Controller" before.
namespace Application\Controller;
// Calling some useful Zend's libraries.
use Zend\Mvc\Controller\AbstractActionController;
use Zend\View\Model\ViewModel;
use Zend\Session\Config\SessionConfig;
use Zend\Session\Container;
// Default controller ; will be calling when the user access the "easygoing/" page.
// Be careful about the class' name, which must be the same as the file's name.
class UserController extends AbstractActionController
{
	// The user's model used to communicate with the database.
	private $userTable;
	// Will contain the Utility class.
	private $_utilities;

	// Get the user's table's entity, represented by the created model.
	// Act as a singleton : we only can have one instance of the object.
	private function _getUserTable()
	{
		// If the object is not currencly instanciated, we do it.
		if (!$this->userTable) {
			$sm = $this->getServiceLocator();
			// Instanciate the object with the created model.
			$this->userTable = $sm->get('Application\Model\UserTable');
		}
		return $this->userTable;
	}

	// Get utilities functions.
	// Act as a singleton : we only can have one instance of the object.
	private function _getUtilities()
	{
		if (!$this->_utilities) {
			$sm = $this->getServiceLocator();
			$this->_utilities = $sm->get('Application\Utility\Utilities');
		}
		return $this->_utilities;
	}

   private function _hashPassword($password)
   {
      return hash ("sha256", $password, false);
   }

   // Default action of the controller.
   // In normal case, it will be calling when the user access the "easygoing/myController/" page,
   // but here we are in the default controller so the page will be "easygoing/".
   public function indexAction()
   {
		$sessionUser = new container('user');

		//checks if the user has a valid loginCookie:
		if (isset($_COOKIE['loginCookie'])){

			$loginCookie = $_COOKIE['loginCookie'];

			$userUsingCookie = $this->_getUserTable()->getUserByCookie($loginCookie);
			//the cookie is already in the db
			if(!$userUsingCookie == null)
			{
				//add session attributes
				$sessionUser->connected = true;
				$sessionUser->id = $userUsingCookie->id;
				$sessionUser->username = $userUsingCookie->username;
				$this->redirect()->toRoute('projects');
				return new ViewModel();
			}
		}

		// Checks if the user isn't already connected.
		if ($sessionUser && $sessionUser->connected)
		{
			// Redirect the user if he is already connected.
			$this->redirect()->toRoute('projects');
		}
		else
		{
			$request = $this->getRequest();
			if ($request->isPost())
			{
				$username = $_POST["username"];
				$password = $_POST["password"];
				$hashPassword = $this->_hashPassword($password);
				//Check if creditentials are correct
				$user = $this->_getUserTable()->checkCreditentials($username,$hashPassword);
				//If so, user is not null
				if(!$user == null)
				{
					//add session attributes
					$sessionUser->connected = true;
					$sessionUser->id = $user->id;
					$sessionUser->username = $user->username;
					$sessionUser->wantTutorial = $user->wantTutorial;

					//Check if the user has ticked "Remember Me" button
					//If so, create a cookie
					if (isset($_POST['checkbox']))
					{
						// Set cookie expiration time to 30 days
						$expirationTime = 60*60*24*30 ;
						// We first check if this user already has a cookie
						if(!$user->cookie){
						//If not, we set a secured cookieValue with username, password and random salt
							$salt = rand();
							$cookieValue = $this->_hashPassword($username . $password . $salt);
							//store it in the db
							$this->_getUserTable()->addCookie($cookieValue,$user->id);
							setcookie('loginCookie', $cookieValue, time() + $expirationTime);
						}
						else
						{
							//If so, we retrieve the value of this cookie and store it on user's device
							setcookie('loginCookie', $user->cookie, time() + $expirationTime);
						}
						// We can now retrieve this cookie using : $this->getRequest()->getCookie('loginCookie');
					}

					//go To projects
					$this->redirect()->toRoute('projects');
				}
				else
				{
					// stay here and display log in error
					$error = "loginFailed";
					return new ViewModel(array(
						'error' => $error
					));
				}
		   }
		}

		$successfulRegistration = false;

		// If there is a successful-registration variable in the URL (comming from
		// the 'registration' action), we need to display a success message in the
		// home page.
		if (isset($_GET["successfulRegistration"]) && $_GET["successfulRegistration"])
		{
			$successfulRegistration = true;
		}

      return new ViewModel(array(
			'successfulRegistration'	=> $successfulRegistration
		));
   }

   public function registrationAction()
   {
		define("SUCCESS_MESSAGE", "ok");
      $sessionUser = new container('user');
      // Checks if the user isn't already connected.
      if ($sessionUser && $sessionUser->connected)
      {
         // Redirect the user if it is already connected.
         $this->redirect()->toRoute("projects");
      }
      else
      {
			// Check if a request is posted ; in other words, check if the user pressed
			// the "Register!" button.
         $request = $this->getRequest();
         if ($request->isPost())
         {
				// Operation's result message.
            $result = SUCCESS_MESSAGE;
            // POST request's values.
            $username= $_POST["username"];
            $fname = $_POST["fname"];
            $lname= $_POST["lname"];
            $password1 = $_POST["password1"];
            $password2 = $_POST["password2"];
            $email =  $_POST["email"];
				$tutorial =  (isset($_POST["tutorial"]) && $_POST["tutorial"]) ? true : false;
				$notifications =  (isset($_POST["notifications"]) && $_POST["notifications"]) ? true : false;
	         // Will be used attribute a name to the uploaded file.
				$filename;
            // Checks that the mandatory fields aren't empty and that the username doesn't
				// contain spaces.
            if (!empty($username) && !ctype_space($username) && !empty($fname) && !empty($lname) && !empty($password1) && !empty($password2) && !empty($email))
            {
					// The username cannot be a reserved one.
					if (strtolower($username) != "system")
					{
	               // The two passwords must match.
	               if ($password1 == $password2)
	               {
	                  // The mail address must be valid.
	                  if (filter_var($email, FILTER_VALIDATE_EMAIL))
	                  {
	                     // The email must not already exist.
	                     if(!$this->_getUserTable()->checkIfMailExists($email))
	                     {
									// Indicate if the prospective user's picture is valid or not.
	                        $fileValidated = true;
	                        // If the user mentioned a picture, validate it.
	                        if (!empty($_FILES["picture"]["name"]))
	                        {
	                           // Allowed file's extensions.
	                           $allowedExts = array("jpeg", "JPEG", "jpg", "JPG", "png", "PNG");
	                           // Get the file's extension.
	                           $temp = explode(".", $_FILES["picture"]["name"]);
	                           $extension = end($temp);
	                           // Validates the file's size.
	                           if ($_FILES["picture"]["size"] > 5 * 1024 * 1024 || !$_FILES["picture"]["size"])
	                           {
	                              $result = "errorPictureSize";
	                              $fileValidated = false;
	                           }
				                  // Validates the file's type.
	                           else if (($_FILES["picture"]["type"] != "image/jpeg") &&
	                              ($_FILES["picture"]["type"] != "image/jpg") &&
	                              ($_FILES["picture"]["type"] != "image/pjpeg") &&
	                              ($_FILES["picture"]["type"] != "image/x-png") &&
	                              ($_FILES["picture"]["type"] != "image/png"))
	                           {
	                              $result = "errorPictureType";
	                              $fileValidated = false;
	                           }
	                           // Validates the file's extension.
	                           else if (!in_array($extension, $allowedExts))
	                           {
	                              $result = "errorPictureExtension";
	                              $fileValidated = false;
	                           }
	                           // Check that there is no error in the file.
	                           else if ($_FILES["picture"]["error"] > 0)
	                           {
	                              $result = "errorPicture";
	                              $fileValidated = false;
	                           }
				                  // If the file is valid, upload the picture.
	                           else
	                           {
	                              try
	                              {
	                                 // Generate a time-based unique ID, and check that this file's name doesn't exist yet.
	                                 do
	                                 {
	                                    $fileName = uniqid() . ".png";
	                                 }
	                                 while (file_exists(getcwd() . "/public/img/users/" . $fileName));
												// First move the temporary uploaded file in the server's directory to
				                        // avoid some extensions issues with some OS.
				                        move_uploaded_file($_FILES['picture']['tmp_name'], getcwd() . "/public/img/users/tmp/" . $_FILES["picture"]["name"]);
	                                 // Then create a thumbnail (50px) of the image and save it in the hard drive of the server.
	                                 $this->_getUtilities()->createSquareImage(getcwd() . "/public/img/users/tmp/" . $_FILES["picture"]["name"], $extension, getcwd() . "/public/img/users/" . $fileName, 150);
	                              }
	                              catch (\Exception $e)
	                              {
	                                 $result = "errorFilesUpload";
	                              }
											// Delete the temporary file if it exists.
											if (file_exists(getcwd() . "/public/img/users/tmp/" . $_FILES["picture"]["name"]))
												unlink(getcwd() . "/public/img/users/tmp/" . $_FILES["picture"]["name"]);
	                           }
	                        }
									// If there is no file or the file is valid, we can add the new
				               // user in the database.
				               if ($fileValidated)
				               {
				                  // Adds the new user in the database.
				                  if ($result == SUCCESS_MESSAGE)
				                  {
			                        try
			                        {
			                           $userId = $this->_getUserTable()->addUser(
													$username,
													$this->_hashPassword($password1),
													$fname,
													$lname,
													$email,
													isset($fileName) ? $fileName : "default.png",
													$tutorial,
													$notifications
												);
			                        }
			                        catch (\Exception $e)
			                        {
			                           $result = 'errorDatabaseAdding';
			                        }
										}
									}
	                     }
	                     else
								{
	                        $result = 'errorEmailAlreadyExists';
								}
	                  }
	                  else
							{
	                     $result = 'errorEmailInvalid';
							}
	               }
	               else
						{
	                  $result = 'errorPasswordsDontMatch';
						}
					}
					else
					{
						$result = "errorReservedUsername";
					}
	         }
            else
				{
               $result = "errorFieldEmpty";
				}

				// Deletes the uploaded file if there was an error.
				// If not, redirect the user.
				if ($result == SUCCESS_MESSAGE)
				{
					$this->redirect()->toRoute(
						"home",
						array(),
						array('query' => array(
					   	'successfulRegistration'	=> true
						))
					);
				}
				else
				{
					// Deletes the thumbnail if it exists.
					if (isset($fileName) && file_exists(getcwd() . "/public/img/users/" . $fileName))
						unlink(getcwd() . "/public/img/users/" . $fileName);

					return new ViewModel(array(
						'error' 		=> $result,
						'username'	=> $username,
						'fname'		=> $fname,
						'lname'		=> $lname,
						'email'		=> $email
					));
				}
         }

         return new ViewModel();
      }
	}

	public function logoutAction()
	{
		$sessionUser = new container('user');
		$sessionUser->offsetUnset("connected");
		$sessionUser->offsetUnset("id");
		$sessionUser->offsetUnset("username");
		$this->redirect()->toRoute('user');
		if (isset($_COOKIE['loginCookie']))
		{
		    unset($_COOKIE['loginCookie']);
		    setcookie('loginCookie', null, -1, '/');;
		}
		return new ViewModel();
	}

	public function editAction()
	{
		$sessionUser = new container('user');
		//We first check that the user is connected
		if(!$sessionUser->connected){
			// If not, we redirect him to index
			$this->redirect()->toRoute();
			return new ViewModel();
		}
		else
			//If so, we send user's information to edit view
		{
		$user = $this->_getUserTable()->getUserById($sessionUser->id);
		$request = $this->getRequest();
		define("SUCCESS_MESSAGE", "ok");
      $result = SUCCESS_MESSAGE;
		$successfulEdition = "";

		if ($request->isPost())
        {

         	//the user has clicked on "Save changes"
         	$username = $_POST["username"];
            $fName = $_POST["fName"];
            $lName= $_POST["lName"];
            $email =  $_POST["email"];
            if(isset($_POST["wantTutorial"]))
            {
            	$wantTutorial = $_POST["wantTutorial"];
            }
         	else
         	{
         		$wantTutorial = 0;
         	}
			if(isset($_POST["wantNotifications"]))
            {
            	$wantNotifications = $_POST["wantNotifications"];
            }
         	else
         	{
         		$wantNotifications = 0;
         	}

         	$password1 = $_POST["password1"];
            $password2 = $_POST["password2"];
            //by default, the password has not been changed
			$password = $user->hashedPassword;
			$id = $user->id;
            if($password1 != $password2)
            {
            	$result = 'errorPasswordsDontMatch';
            	return new ViewModel(array(
						'error' 			=> $result
				));
            }
            else if ($password1 == "") { //password hasn't been changed
            	//nothing to do
            }
            else//password has changed
            {
            	$password = $password1;
            	$this->_getUserTable()->updateUserPassword($id, $this->_hashPassword($password));
            }

            $picture = $_POST["picture"];
         	//update user's information in DB

         	if($this->_getUserTable()->checkIfMailExists($email))
         	{
         		$result = 'errorEmailAlreadyExists';
            	return new ViewModel(array(
						'error' 			=> $result
				));
         	}

         	$this->_getUserTable()->updateUser($id, $fName, $lName, $email, $picture, $wantTutorial, $wantNotifications);
         	$successfulEdition = "successfulEdition";
        }

				return new ViewModel(array(
						'successfulEdition' => $successfulEdition,
						'username' 			=> $user->username,
						'email'				=> $user->email,
						'fName'				=> $user->firstName,
						'lName'				=> $user->lastName,
						'wantNotifications'	=> $user->wantNotifications,
						'wantTutorial'		=> $user->wantTutorial,
						'picture'			=> $user->filePhoto
					));

		}


	}

	public function passwordforgottenAction()
	{
		$request = $this->getRequest();
		if ($request->isPost()){
			$email =  $_POST["email"];
			//check if email exists in DB
			if(!$this->_getUserTable()->checkIfMailExists($email))
				//If not, send an error
         	{
         		$result = 'errorEmailDoesNotExist';
            	return new ViewModel(array(
						'error' 			=> $result
				));
         	}
         	else
         		//The given mail corresponds to a mail in DB
         	{
         		//create a random password of length 4*2
         		$newPassword = bin2hex(openssl_random_pseudo_bytes(4));
         		//update user's password
         		$user = $this->_getUserTable()->getUserByMail($email);
				$this->_getUserTable()->updateUserPassword($user->id, $this->_hashPassword($newPassword));
				//send mail
				$subject = "Your new password with EasyGoing";
				$msg = "Hello " . $user->username .",
						/n
						/n
						Someone has reset your password in your EasyGoing account. If you are this person,
						no problem. If you are not, you may asked yourself why someone has wanted to reset
						your password. /n

						Anyway here is your new password: " . $newPassword ."/n

						Feel free to change it when you come back on EasyGoing /n /n

						EasyGoing Team
						";

				mail($email,$subject,$msg);
				$result = "success";
         	}

		}
		return new ViewModel(array(
						'successfulMail' 			=> $result,
						'email'						=> $email
				));
	}

	public function validationAction()
	{
		$this->redirect()->toRoute();
		return new ViewModel();
	}

	public function cancelAction()
	{
		$this->redirect()->toRoute();
		return new ViewModel();
	}
}
