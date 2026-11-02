<?php
namespace Authwave\Security;

/** Retains the public id field and named parameter contract.
 * @SuppressWarnings("PHPMD.ShortVariable")
 */
class AnonUser {
	public readonly string $id;

	/** Retains the public id field and named parameter contract.
	* @SuppressWarnings("PHPMD.ShortVariable")
	*/
	public function __construct(string $id) {
		$this->id = substr($id, -8);
	}
}
