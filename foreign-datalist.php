<?php
/**
 * This allows you attach a datalist dropdown interface right below any input field of your choice.
 * Useful when you need to search thru values that are stored on another table.
 *
 * The 'datalisted' field MUST have the same name of the dimension table, followed by fieldsufix.
 *   Eg: 'product_id' means table='product' and sufix='_id'.
 *
 * The datalist is ajax-attached to each input right after you click on input field..
 *
 * There are 3 ways to present the datalist: DownKey, MouseClick or Start Type Something.
 *
 * The list are presented in the following format 'value:extraDescription'
 * extraDescription helps on type-filtering but only 'value' are returned.
 *
 * Everything needed is bundled in this file. No external dependencies.
 *
 * Tested with mysql 5.7 and php 7.3
 *
 * @author Marcelo Gennari, https://gren.com.br/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License, version 2 (one or other)
 * @version 1.0.0
 */
class AdminerForeignDatalist {

	/**
	* @param string fieldsufix: Fields terminated with this string will activate the datalist. Defaults to '_id'
	* @param string value: Returned field of the external table. Defaults to 'id'.
	* @param string extraDescription: field that contains additional information. Nullable. Defaults to 'description'.
	* @param string active: 'enum('0','1') field used to exclude values you dont want to bring to the datalist.
	* @param int limit: Max datalist size. Defaults to 5000.
	*/
	function __construct($fieldsufix = '_id', $value = 'id'
			, $extraDescription = 'description', $active = 'active', $limit = 5000) {
		$this->fieldsufix = $fieldsufix;
		$this->value = $value;
		$this->extraDescription = $extraDescription;
		$this->active = $active;
		$this->limit = $limit;
	}

	// react to plugin ajax-requests with json
	public function headers() {
		if(isset($_POST["foreignDatalist"])) { // ajax call?
			set_time_limit(5);
			// Sanitize inputs
			$table = preg_replace("/[^a-zA-Z0-9_-]/", "", $_POST["foreignDatalist"]);
			$db = preg_replace("/[^a-zA-Z0-9_-]/", "", $_GET["db"]);
			unset($_POST["foreignDatalist"]);
			// Build query
			$query = "SELECT";
			if ($this->extraDescription) {
				$query .= " concat($this->value, ':', $this->extraDescription)";
			} else {
				$query .= " $this->value";
			}
			$query .= " AS dropdownvalue FROM $db.$table";
			if ($this->active) {
				$query .= " WHERE $this->active = '1'";
			}
			if ($this->limit) {
				$query .= " LIMIT $this->limit";
			}
			// Results
			$jsonArray = get_vals($query);
			if (empty($jsonArray)) { $jsonArray = array(':error'); }
			echo json_encode($jsonArray);
			// Stop everything
			die();
		}
	}

	public function head() {
		if(! isset($_GET["edit"]))
			return; // all below is valid only on edit interfaces
		?>
		<script <?php echo nonce()?> type='text/javascript'>
		// attach mousedown listeners
		document.addEventListener('DOMContentLoaded', function() {
			var searchFieldDropDowns=document.querySelectorAll('tr th'); // field names
			for (let searchFieldDropDown of searchFieldDropDowns ) {
				if ( searchFieldDropDown.innerText.match(/<?php echo $this->fieldsufix; ?>$/) ) {
					var inputfield = searchFieldDropDown.parentElement.getElementsByTagName('input')[0]; // input field
					inputfield.setAttribute("autocomplete", "off"); // disable browser-integrated autocomplete
					inputfield.addEventListener('mousedown', populateAutocompleteDataList);
					// inputfield.style.border = "2px dashed red";
				}
			}
		});

		function populateAutocompleteDataList(ev) {
			// define the table name
			var table = '';
			var fieldsufix = '<?php echo $this->fieldsufix; ?>';
			table = ev.target.parentElement.parentElement.getElementsByTagName('th')[0].innerText;
			table = encodeURIComponent(table.slice(0, (-1 * fieldsufix.length))); // Strips the suffix out
			// datalist 'id' html attribute
			var fkDropDownId = table + "_dropdown";
			// create datalist object if it does not exist
			if(! document.getElementById(fkDropDownId)) {
				var dataList = document.createElement('datalist');
				dataList.setAttribute('id', fkDropDownId);
				ev.target.append(dataList);
			}
			var dataList = document.getElementById(fkDropDownId);
			if(dataList.getAttribute("filled") == 'true') {
				return; // datalist already filled. quit
			}
			// fills datalist with ajax-returned values
			dataList.innerHTML = "";
			var autoCompleteXHR = new XMLHttpRequest();
			autoCompleteXHR.open("POST", "", true);
			autoCompleteXHR.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
			autoCompleteXHR.send("foreignDatalist=" + table );
			autoCompleteXHR.onreadystatechange = function() {
				if (this.readyState == 4 && this.status == 200) {
					var response = JSON.parse(this.responseText);
					response.forEach(function(item) {
						// Create a new <option> element.
						var option = document.createElement('option');
						option.value = item.split(':')[0]; // valor a ser recuperado
						option.innerHTML = item; // valor a ser apresentado na lista
						// attach the option to the datalist element
						dataList.appendChild(option);
					});
				}
			}
			// indicates that datalist is filled
			dataList.setAttribute("filled", 'true');
			// attach the datalist to this input field
			ev.target.setAttribute('list', fkDropDownId);
			// ev.target.focus();
		}
		</script>
		<?php
	}
}
