<?php
namespace Application\Model;

use Zend\Db\TableGateway\TableGateway;

// Contains the methods that allows to work with an user entity.
class UserTable
{
	protected $tableGateway;

	public function __construct(TableGateway $tableGateway)
	{
		$this->tableGateway = $tableGateway;
	}

	// Check an user's creditentials, to ensure it logged in correctly.
	// Return 'true' if creditentials are right, and 'false' if wrong.
	public function checkCreditentials($username, $hashedPassword)
	{
		// Try to get the row that matchs with the given username and hashed
		// password.
		$rowset = $this->tableGateway->select(array(
			'username' => $username,
			'hashedPassword' => $hashedPassword
		));
		$row = $rowset->current();

		// Return true or false, depending on the given creditentials'
		// correctness.
		return $row ? true : false;
	}
	// Checks if the given e-mail address doesn't already exist in the DB.
		public function checkIfMailExists($email)
		{
			$rowset = $this->tableGateway->select(array('email' => $email));
			$row = $rowset->current();
			return $row;
		}

		// add a new user
		public function addUser($username, $password, $firstName, $lastName, $email, $picture)
{
	$this->tableGateway->insert(array(
		'username'				=> $username,
		'password'			=> $password,
		'firstName'			=> $firstName,
		'lastName'			=> $lastName,
		'email'				=> $email,
		'picture'			=> $picture,
	));

	return $this->tableGateway->lastInsertValue;
}


}
