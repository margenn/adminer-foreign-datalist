<?php
/**
 * IMPORTANT: Please, activate struct-comments.php plugin before continue.
 * It is necessary because this plugin needs to read the field's comment in html interface.
 *
 * USAGE EXAMPLE:
 *
 * Lets say you have a field called EMPLOYEE_ID in the table ORDERS and you want fill it with
 *   employee's ID by typing any part of it's name or phone number that are stored in another table.
 *   The list must show only ACTIVE employees and return a maximum of 999 rows.
 *
 * Alter the structure of ORDERS table and insert the string below in the EMPLOYEE_ID comment:
 *
 * "dropdown:{table:HR.EMPLOYEES, label:[FULL_NAME, PHONE], value:ID, filter:ACTIVE, limit:999}"
 *
 * filter and limit are optional, where:
 *   filter: will add a "WHERE ACTIVE = '1'" in sql query
 *   limit: will overwrite the default value (10000)
 *
 * Do NOT put any parameter inside Quotation Marks (").
 *
 * In the EDIT interface, the user will click on EMPLOYEE_ID and start typing.
 * The click will start the plugin that reads the comment, make an ajax call, read the HR.EMPLOYEES
 *   table, return a json with results (select2.org format) and attach then as a datalist.
 *
 * The main structure of this plugin was taken from
 * https://github.com/derStephan/AdminerPlugins/blob/master/searchAutocomplete.php thanks!
 *
 * This approach has some advantages:
 *   No ajax call is done, unless user clicks in the field.
 *   Results are cached on browser until page reload (field became lightgreen)
 *   Ordinary users can change dropdown behavior, just editing the field's comment
 *   The field still accepts any value, even if not in the list
 *   No need of an external table to keep configurations
 *   Dropdown data-origin can be checked with a simple mouseover on the field's title
 *
 * Tested with php 7.0~8.3 / mysql 5.7~8.0
 *
 * @author Marcelo Gennari, https://gren.com.br/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 * @param string placeholder: Placeholder applied to the field.
 * @param int limit: Max datalist size. Defaults to 10000.
 * @version 2.1.1
 */

class AdminerForeignDatalist {

	/** @access protected */
	var $placeholder, $limit;

	function __construct(
		$placeholder = 'Show dropdown: ⬆️ ⬇️ . 🔠: starts filter',
		$limit = 10000
	) {
		$this->placeholder = $placeholder;
		$this->limit = $limit;
	}

	// answer ajax requests with json
	public function headers() {
		if(isset($_POST["foreignDatalistGren"])) { // ajax call?
			try {
				set_time_limit(5);
				$payload = json_decode($_POST["foreignDatalistGren"]);
				unset($_POST["foreignDatalistGren"]);
				// prepare values from payload
				$labels = (gettype($payload->jsonFromComment->labels) == 'array')
						? $payload->jsonFromComment->labels : [$payload->jsonFromComment->labels];
				$value = [$payload->jsonFromComment->value]; // array
				$filter = isset($payload->jsonFromComment->filter) ? $payload->jsonFromComment->filter : '';
				$limit = (isset($payload->jsonFromComment->limit) && preg_match('~^\d{1,5}$~', $payload->jsonFromComment->limit))
						? $payload->jsonFromComment->limit
						: $this->limit;
				// build query
				$select = 'SELECT ' . implode(", ", array_unique(array_merge($labels, $value)));
				$from = ' FROM ' . $payload->jsonFromComment->table;
				$where = $filter ? " WHERE $filter = '1'" : '';
				$limit = " LIMIT $limit";
				$query = $select . $from . $where . $limit . ';';
				// Submit the query and save results into an select2 compatible object
				$connection = connection(); $output = (object) array('results' => array());
				$resultset = $connection->query($query, 1);
				if ($resultset) {
					while ($row = $resultset->fetch_assoc()) {
						$output->results[] = (object) [
							'id' => ($row[$value[0]] ? $row[$value[0]] : ''),
							'text' => implode(", ", array_intersect_key($row, array_flip($labels)))
						];
					}
				} else {
					throw new Exception("No results: ($query)");
				}
			} catch (Exception $ex) {
				$output->results[] = (object) [ 'id' => 'error', 'text' => $ex->getMessage() ];
			}
			echo json_encode($output);
			die(); // stop
		}
	}

	public function head() {
		// INICIAL CHECK
		// interface must be 'edit'
		if (! isset($_GET['edit']) ) { return; }
		// $fields can't be null
		$fields = fields($_GET["edit"]); if (! $fields ) { return; }
		// AdminerStructComments must be loaded
		$adminer = adminer();
		$regex = '~AdminerStructComments.*~';
		$hasStructCommentsPlugin = array_filter($adminer->plugins, function($item) use ($regex) { return preg_match($regex, get_class($item)); });
		if (! $hasStructCommentsPlugin ) { echo script("alert('" . get_class($this) . " depends on AdminerStructComments.')"); return; }
		// at least one "dropdownable" field
		$dropDownableFields = [];
		foreach ($fields as $field) { if ( preg_match("~dropdown *: *\{.+\}~", $field['comment'] )) { $dropDownableFields[] = $field['field']; } }
		if (! $dropDownableFields ) { return; }
		// ALL ABOVE IS OK, PROCEED
		$dropDownableFieldsJs = '[' . implode(", ", array_map(function($item) { return "'$item'"; }, $dropDownableFields)) . ']';
		?>

		<script <?php echo nonce()?> type='text/javascript'>
		// attach mousedown listeners
		document.addEventListener('DOMContentLoaded', function() {
			let dropDownableFields = <?php echo $dropDownableFieldsJs ?>.map(item => `fields[${item}]`);
			dropDownableFields.forEach(item => {
				let dropDownableField = document.getElementsByName(item)[0];
				dropDownableField.addEventListener('mousedown', populateDatalist); // event
				dropDownableField.parentElement.classList.add('datalist-ajax'); // css
				dropDownableField.placeholder = `<?php echo $this->placeholder ?>`; // css
				let tmp = document.createElement('span'); tmp.className="spinner"; dropDownableField.insertAdjacentElement('afterend', tmp);
				if (dropDownableField.getAttribute('type') == 'number') {
					// make field bigger and remove only-numbers constraint
					dropDownableField.setAttribute('size', '40'); dropDownableField.removeAttribute('type');
				}
			});
		});
		function populateDatalist(ev) {
			// ASSEMBLE PAYLOAD
			const input = ev.target; let fieldValue = input.value.trim();
			let jsonFromComment = extractJsonFromComment(input.closest("tr").querySelector("[title]").getAttribute("title"));
			let payload = {'fieldValue': fieldValue, 'jsonFromComment': JSON.parse(jsonFromComment)};
			// CREATE DATALIST OBJECT
			let datalistId = input.name.match(/fields\[(.+)\]/)[1] + '_datalist';
			if ( ! document.getElementById(datalistId) ) {
				let dataList = document.createElement('datalist');
				dataList.setAttribute('id', datalistId);
				input.parentElement.append(dataList);
			}
			// QUIT IF INPUT ALREADY HAS A DATALIST (CACHE)
			if ( input.getAttribute('list') == datalistId ) { return; }
			// BEFORE AJAX
			var dataList = document.getElementById(datalistId);
			dataList.innerHTML = ''; // attach/clear datalist
			input.removeEventListener('mousedown', populateDatalist); // block further ajax calls
			input.parentElement.classList.add('datalist-ajax-pending'); // css

			// AJAX
			const ajax = new XMLHttpRequest(); console.debug("chamada ajax iniciada")
			ajax.open('POST', '', true); // '':this url true:asyncmode
			ajax.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
			ajax.send('foreignDatalistGren=' + JSON.stringify(payload));
			ajax.onreadystatechange = function() {
				if (this.readyState == this.DONE) {
					let responseObj = null;
					if (this.status == 200) {
						try {
							responseObj = JSON.parse(this.responseText);
						} catch (e) {
							console.warn(e.message)
							if (match = this.responseText.match(/(\{"result[\s\S]+\}\]\})/)) {
								responseObj = JSON.parse(match[0]); // 2nd try
							} else {
								responseObj = {results: [{'id':'error', 'text': e.message}]};
								console.error(e.message);
							}
						}
					} else {
						responseObj = {results: [{'id':'error', 'text': `${this.statusText}: ${this.status}`}]};
					}
					input.setAttribute('autocomplete', 'off'); // clear autocomplete
					if ( typeof responseObj.results == 'object' && responseObj.results.length > 1 ) { // is responseObj ok?
						responseObj.results.forEach(item => {
							let tmpOption = document.createElement('option'); tmpOption.value = item.id; tmpOption.label = item.text;
							dataList.appendChild(tmpOption);
						});
						input.setAttribute('list', datalistId); // attach datalist
						input.addEventListener('mousedown', populateDatalist); // reattach listener
						input.parentElement.classList.replace('datalist-ajax-pending', 'datalist-ajax-done-ok');
					} else {
						input.placeholder = 'Error. F12 > Console for details';
						console.error(responseObj.results[0].text);
						input.parentElement.classList.replace('datalist-ajax-pending', 'datalist-ajax-done-error'); // css
					}
				}
			}
		}
		function extractJsonFromComment(comment) {
			let extracted = comment.match(/dropdown *: *(\{.+\})/i)[1].replace(/["']/g, '');
			let $return = extracted.replace(/[\w\-._]+/g, match => `"${match}"`);
			return $return;
		}
		</script>

		<?php
	}

}