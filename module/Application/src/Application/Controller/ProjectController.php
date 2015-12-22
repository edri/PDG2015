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
use Zend\View\Model\JsonModel;
use Zend\Session\Config\SessionConfig;
use Zend\Session\Container;
use Zend\Http\Client;
use Zend\Http\Request;
use Application\Utility\Priority;

// Project controller ; will be calling when the user access the "easygoing/project" page.
// Be careful about the class' name, which must be the same as the file's name.
class ProjectController extends AbstractActionController
{
   // Get the given table's entity, represented by the created model.
   private function _getTable($tableName)
   {
      $sm = $this->getServiceLocator();
      // Instanciate the object with the created model.
      $table = $sm->get('Application\Model\\'.$tableName);

      return $table;
   }

   // Acts like a filter : every request go through the dispatcher, in which we
   // can do some stuff.
   // In this case, we just prevent unconnected users to access this controller
   // and check if the accessed project/task exists.
   public function onDispatch( \Zend\Mvc\MvcEvent $e )
   {
      $sessionUser = new container('user');

      if (!$sessionUser->connected)
         $this->redirect()->toRoute('home');

      if (empty($this->_getTable('ProjectTable')->getProject($this->params('id'))))
         $this->redirect()->toRoute('projects');

      if ($this->params('otherId') != null && empty($this->_getTable('TaskTable')->getTaskById($this->params('otherId'))))
         $this->redirect()->toRoute('projects');

      return parent::onDispatch( $e );
   }

   public function indexAction()
   {
      $sessionUser = new container('user');
      $project = $this->_getTable('ProjectTable')->getProject($this->params('id'));
      $tasks = $this->_getTable('TaskTable')->getAllTasksInProject($this->params('id'));
      $members = $this->_getTable('ViewUsersProjectsTable')->getUsersInProject($this->params('id'));
      // Get projects' events types.
      $eventsTypes = $this->_getTable('EventTypeTable')->getTypes(false);
      // Get project's events.
      $events = $this->_getTable('ViewEventTable')->getEntityEvents($this->params('id'), false);
      $isManager = $this->_userIsAdminOfProject($sessionUser->id, $this->params('id'));

      
      return new ViewModel(array(
         'project'      => $project,
         'tasks'        => $tasks,
         'members'      => $members,
         'eventsTypes'  => $eventsTypes,
         'events'       => $events,
         'isManager'    => $isManager ? 'true' : 'false'
      ));
   }

   public function taskAction()
   {
      return new ViewModel(array(
         'id' => $this->params('id')
      ));
   }

   public function addTaskAction()
   {
      $request = $this->getRequest();

      if($request->isPost())
      {
         $sessionUser = new container('user');

         $projectId = $this->params('id');
         $name = $_POST["name"];
         $description = $_POST["description"];
         $priority = $_POST["priority"];
         $deadline = $_POST["deadline"];
         $duration = $_POST["duration"];
         $sessionUser = new container('user');

         $taskId = $this->_getTable('TaskTable')->addTask($name, $description, $deadline, $duration, $priority, $projectId);
         $this->_getTable('UsersTasksAffectationsTable')->addAffectation($sessionUser->id, $taskId);

         // If task was successfully added, add two task's creation events: one for
         // the project's history, and another for the new task's news feed.
         // For the project's history.
         // First of all, get right event type.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Tasks")->id;
         // Then add the new event in the database.
         $message = "<u>" . $sessionUser->username . "</u> created task <font color=\"#FF6600\">" . $name . "</font>.";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         // Link the new event to the current project.
         $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
         // Finaly link the new event to the user who created it.
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         // Get event's data to send them to socket server.
         $event1 = $this->_getTable("ViewEventTable")->getEvent($eventId, false);
         // For the task's news feed.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Info")->id;
         $message = "<u>" . $sessionUser->username . "</u> created the task.";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         $this->_getTable("EventOnTaskTable")->add($eventId, $taskId);
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         $event2 = $this->_getTable("ViewEventTable")->getEvent($eventId, true);

         try
         {
            // Make an HTTP POST request to the event's server so he can broadcast a
            // new websocket related to the new event.
            $client = new Client('http://127.0.0.1:8002');
            $client->setMethod(Request::METHOD_POST);
            // Setting POST data.
            $client->setParameterPost(array(
               "requestType"  => "newEvents",
               "events"       => array(json_encode($event1), json_encode($event2))
            ));
            // Send HTTP request to server.
            $response = $client->send();
         }
         catch (\Exception $e)
         {
            error_log("WARNING: could not connect to events servers. Maybe offline?");
         }

         $this->redirect()->toRoute('project', array(
             'id' => $projectId
         ));
      }
   }

   public function taskDetailsAction()
   {
      $taskId = $this->params('otherId');
      $task = $this->_getTable('TaskTable')->getTaskById($taskId);
      // Get tasks' events types.
      $eventsTypes = $this->_getTable('EventTypeTable')->getTypes(true);
      // Get task's events.
      $events = $this->_getTable('ViewEventTable')->getEntityEvents($taskId, true);

      return new ViewModel(array(
         'task'         => $task,
         'eventsTypes'  => $eventsTypes,
         'events'       => $events
      ));
   }

   public function editTaskAction()
   {
      $request = $this->getRequest();

      if($request->isPost())
      {
         $sessionUser = new container('user');
         $projectId = $this->params('id');

         $id = $_POST["id"];
         $name = $_POST["name"];
         $description = $_POST["description"];
         $priority = $_POST["priority"];
         $deadline = $_POST["deadline"];
         $duration = $_POST["duration"];

         $this->_getTable('TaskTable')->updateTask($name, $description, $deadline, $duration, $priority, $id);

         // If task was successfully edited, add a task's edition event.
         // First of all, get right event type.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Tasks")->id;
         // Then add the new event in the database.
         $message = "<u>" . $sessionUser->username . "</u> updated task <font color=\"#FF6600\">" . $name . "</font>.";
         $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
         // Link the new event to the current project.
         $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
         // Finaly link the new event to the user who created it.
         $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
         // Get event's data to send them to socket server.
         $event = $this->_getTable("ViewEventTable")->getEvent($eventId, false);

         try
         {
            // Make an HTTP POST request to the event's server so he can broadcast a
            // new websocket related to the new event.
            $client = new Client('http://127.0.0.1:8002');
            $client->setMethod(Request::METHOD_POST);
            // Setting POST data.
            $client->setParameterPost(array(
               "requestType"  => "newEvent",
               "event"        => json_encode($event)
            ));
            // Send HTTP request to server.
            $response = $client->send();
         }
         catch (\Exception $e)
         {
            error_log("WARNING: could not connect to events servers. Maybe offline?");
         }

         $this->redirect()->toRoute('project', array(
             'id' => $this->params('id')
         ));
      }
      else
      {
         $taskId = $this->params('otherId');
         $task = $this->_getTable('TaskTable')->getTaskById($taskId);

         return new ViewModel(array(
               'task' => $task
            ));
      }
   }

   public function boardViewMembersAction()
   {
      // Get members of a project
      $members = $this->_getTable('ViewUsersProjectsTable')->getUsersInProject($this->params('id'));

      // Get tasks in a project for each member
      $arrayTasksForMember = array();
      foreach($members as $member)
      {
         $arrayTasksForMember[$member->id] = array();
         $tasksForMember = $this->_getTable('ViewUsersTasksTable')->getTasksForMemberInProject($this->params('id'), $member->id);
         foreach($tasksForMember as $task)
            array_push($arrayTasksForMember[$member->id], $task);
      }

      $result = new ViewModel(array(
         'projectId'         => $this->params('id'),
         'members'           => $members,
         'tasksForMember'    => $arrayTasksForMember
      ));
      $result->setTerminal(true);

      return $result;
   }

   public function boardViewTasksAction()
   {
      // Get tasks in a project
      $tasks = $this->_getTable('TaskTable')->getAllTasksInProject($this->params('id'));

      // Get user(s) doing a task
      $arrayMembersForTask = array();
      foreach($tasks as $task)
      {
         $arrayMembersForTask[$task->id] = array();
         $membersForTask = $this->_getTable('ViewTasksUsersTable')->getUsersAffectedOnTask($task->id);
         foreach($membersForTask as $member)
            array_push($arrayMembersForTask[$task->id], $member);
      }

      $result = new ViewModel(array(
         'projectId'         => $this->params('id'),
         'tasks'             => $tasks,
         'membersForTask'    => $arrayMembersForTask
      ));
      $result->setTerminal(true);

      return $result;
   }

   public function moveTaskAction() {
      $sessionUser = new container('user');
      $projectId = $this->params('id');
      // Get POST data
      $data = $this->getRequest()->getPost();
      $hasRightToMoveTask = true;


      // Check if current user has rights to move the task
      if($this->_userIsAdminOfProject($sessionUser->id, $projectId)
        || $this->_getTable('UsersTasksAffectationsTable')->getAffectation($sessionUser->id, $data['taskId']))
      {
         $this->_getTable('TaskTable')->updateStateOfTask($data['taskId'], $data['targetSection']);
         
         if($data['oldMemberId'] != $data['targetMemberId'])
            $this->_getTable('UsersTasksAffectationsTable')->updateTaskAffectation($data['oldMemberId'], $data['taskId'], $data['targetMemberId']);
      }
      else
      {
         $hasRightToMoveTask = false;
      }


      // If task was successfully moved, add a task's movement event.
      // First of all, get right event type, moved task's name and old/new task's user's name.
      $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Tasks")->id;
      $name = $this->_getTable("TaskTable")->getTaskById($data['taskId'])->name;
      $oldUsername = $this->_getTable("UserTable")->getUser($data['oldMemberId'])->username;
      $newUsername = $this->_getTable("UserTable")->getUser($data['targetMemberId'])->username;
      // Then add the new event in the database.
      $message = "<u>" . $sessionUser->username . "</u> moved task <font color=\"#FF6600\">" . $name . "</font> from <font color=\"#995527\">(" . $oldUsername . ", " . $data['oldSection'] . ")</font> to <font color=\"#995527\">(" . $newUsername . ", " . $data['targetSection'] . ")</font>.";
      $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
      // Link the new event to the current project.
      $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
      // Finaly link the new event to the user who created it.
      $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
      // Get event's data to send them to socket server.
      $event = $this->_getTable("ViewEventTable")->getEvent($eventId, false);

      return $this->getResponse()->setContent(json_encode(array(
         'taskId'              => $data['taskId'],
         'targetMemberId'      => $data['targetMemberId'],
         'targetSection'       => $data['targetSection'],
         'event'               => $event,
         'hasRightToMoveTask'  => $hasRightToMoveTask
      )));
   }

   public function deleteTaskAction()
   {
      $projectId = $this->params('id');
      $sessionUser = new container('user');
      $taskId = $this->params('otherId');
      $resMessage = 'Delete success';


      if($this->_userIsAdminOfProject($sessionUser->id, $projectId))
      {
         $this->_getTable('TaskTable')->deleteTask($taskId);
      }
      else
      {
         $resMessage = 'You do not have rights to delete this task !';
      }


      return $this->getResponse()->setContent(json_encode(array(
         'message' => $resMessage
      )));
   }

   public function addMemberAction()
   {
      $sessionUser = new container('user');
      $projectId = $this->params('id');
      $request = $this->getRequest();

      if($request->isPost())
      {
         // Get right event type.
         $typeId = $this->_getTable("EventTypeTable")->getTypeByName("Users")->id;

         foreach ($_POST as $value)
         {
            $this->_getTable('ProjectsUsersMembersTable')->addMemberToProject($value, $this->params('id'));
            // If member was successfully added, add an event.
            // Get new member's username.
            $addedMemberName = $this->_getTable("UserTable")->getUser($value)->username;
            // Then add the new event in the database.
            $message = "<u>" . $sessionUser->username . "</u> added user <u>" . $addedMemberName . "</u> in project.";
            $eventId = $this->_getTable('EventTable')->addEvent(date("Y-m-d"), $message, $typeId);
            // Link the new event to the current project.
            $this->_getTable("EventOnProjectsTable")->add($eventId, $projectId);
            // Finaly link the new event to the user who created it.
            $this->_getTable("EventUserTable")->add($sessionUser->id, $eventId);
            // Get event's data to send them to socket server.
            $event = $this->_getTable("ViewEventTable")->getEvent($eventId, false);

            try
            {
               // Make an HTTP POST request to the event's server so he can broadcast a
               // new websocket related to the new event.
               $client = new Client('http://127.0.0.1:8002');
               $client->setMethod(Request::METHOD_POST);
               // Setting POST data.
               $client->setParameterPost(array(
                  "requestType"  => "newEvent",
                  "event"        => json_encode($event)
               ));
               // Send HTTP request to server.
               $response = $client->send();
            }
            catch (\Exception $e)
            {
               error_log("WARNING: could not connect to events servers. Maybe offline?");
            }
         }

         $this->redirect()->toRoute('project', array(
             'id' => $projectId
         ));
      }

      $usersNotMemberOfProject = $this->_getUsersNotMemberOfProject($this->params('id'));

      //$usersNotMemberOfProject = $this->_getUserTable()->getUsersNotMembersOfProject($this->params('id'));

      return new ViewModel(array(
         'users' => $usersNotMemberOfProject
      ));
   }

   public function removeMemberAction()
   {

   }

   public function loadEventAction()
   {

   }

   public function detailsAction()
   {
      $sessionUser = new container('user');

      // The user must be authenticated to access this part, otherwise he will be
      // redirected to the home page.
      if ($sessionUser && $sessionUser->connected)
      {
         $id = (int)$this->params('id');
         $projectDetails = $this->_getTable('ViewProjectDetailsTable')->getProjectDetails($id, $sessionUser->id);
         $tempMembers = $this->_getTable('ViewProjectsMembersSpecializationsTable')->getProjectMembers($id);
         $members = array();
         $i = 0;

         // Struct the members array.
         foreach ($tempMembers as $tmpM)
         {
            // Indicate whether the current member already exists in the members
            // list or not.
            // If yes, we just have to add the object's specialization to the
            // existing specializations of the user.
            $alreadyExisting = false;
            $nbCurrentMembers = count($members);

            // Check if the current member already exists.
            for ($j = 0; $j < $nbCurrentMembers; ++$j)
            {
               // Add the specialization to the specializations list.
               if ($tmpM->username == $members[$j]["username"])
               {
                  $alreadyExisting = true;
                  $members[$j]["specializations"][] = (empty($tmpM->specialization) ? "-" : $tmpM->specialization);
                  break;
               }
            }

            // If the current member is not already existing in the members list,
            // add it.
            if (!$alreadyExisting)
            {
               $members[$i]["username"] = $tmpM->username;
               $members[$i]["specializations"][] = empty($tmpM->specialization) ? "-" : $tmpM->specialization;
               $members[$i]["isAdmin"] = $tmpM->isAdmin;
               ++$i;
            }
         }

         // Send the success message back with JSON.
         return new JsonModel(array(
            'success' => true,
            'projectDetails' => $projectDetails,
            'members'   => $members
         ));
      }
      else
      {
         return new JsonModel(array(
            'success' => false
         ));
      }
   }

   private function _userIsAdminOfProject($userId, $projectId)
   {
      return $this->_getTable('ViewProjectMinTable')->userIsAdminOfProject($userId, $projectId);
   }

   private function _getUsersNotMemberOfProject($projectId)
   {
      $members = $this->_getTable('ViewUsersProjectsTable')->getUsersInProject($projectId)->buffer();
      $users = $this->_getTable('UserTable')->getAllUsers()->buffer();

      $notMembersArray = array();
      foreach($users as $user)
      {
         $mustAdd = true;

         foreach($members as $member)
         {
            if($user->id == $member->id)
            $mustAdd = false;
         }

         if($mustAdd)
            array_push($notMembersArray, $user);
      }

      return $notMembersArray;
   }
}


?>
