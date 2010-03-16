<?php
declare(ENCODING = 'utf-8');

/*                                                                        *
 * This script belongs to the FLOW3 package "PHPCR".                      *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * An NamedAccessControlPolicy is an opaque access control policy that is described
 * by a JCR name and optionally a description. NamedAccessControlPolicy are
 * immutable and can therefore be directly applied to a node without additional
 * configuration step.
 *
 * @version $Id$
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 * @license http://opensource.org/licenses/bsd-license.php Simplified BSD License
 * @api
 */
interface PHPCR_Security_NamedAccessControlPolicyInterface extends PHPCR_Security_AccessControlPolicyInterface {

	/**
	 * Returns the name of the access control policy, which is JCR name and should
	 * be unique among the choices applicable to any particular node.
	 *
	 * @return string the name of the access control policy. A JCR name.
	 * @throws PHPCR_RepositoryException - if an error occurs.
	 * @api
	 */
	public function getName();

}
?>