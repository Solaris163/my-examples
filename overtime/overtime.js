
$(document).ready(function() {
	jsSelect2Init();

	// кнопки удаления селекта выбора сотрудника
	$('.delete-user-select-button').click(function (e) {
		deleteUserSelect(e);
	});
});

// инициализация селектов с поиском внутри списка
function jsSelect2Init(divClass = '') {
	$(`${divClass} .js-select2-employee`).select2({
		placeholder: "Выберите сотрудника",
		width: "calc(100% - 285px)",
		language: "ru"
	});
	$(`${divClass} .js-select2-from`).select2({
		placeholder: "С",
		width: "100px",
		language: "ru"
	});
	$(`${divClass} .js-select2-to`).select2({
		placeholder: "По",
		width: "100px",
		language: "ru"
	});
}

// показать еще одно поле
function addEmployee() {
	// найти количество уже показанных селектов (userSelectsCounter не всегда равен selectsCount, так как могут быть удаленные поля)
	let selectsCount = $('.select-user').length;
	if (selectsCount >= 20) {
		hideAddEmployeeButton();
		return;
	}

	userSelectsCounter++; // переменная userSelectsCounter объявлена в шаблоне компонента, так как при выводе формы в ней может быть уже любое количество выбранных сотрудников

	let html = `<div class="select-user select-user-${userSelectsCounter}">`;
	html += '<li class="select-user-li">';
	html += `<select class="js-select2-employee" name="${userFieldId}[n${userSelectsCounter}][VALUE]">`;
	html += userOptions;
	html += '</select>';
	html += '<span class="select-user-li-span"> С </span>';
	html += `<select class="js-select2-from" name="${userFieldId}[n${userSelectsCounter}][from]">`;
	html += timeOptions;
	html += '</select>';
	html += '<span class="select-user-li-span"> По </span>';
	html += `<select class="js-select2-from" name="${userFieldId}[n${userSelectsCounter}][to]">`;
	html += timeOptions;
	html += '</select>';
	html += `<span class="delete-user-select-button delete-user-select-button-${userSelectsCounter}">&#128465;</span>`;
	html += '</li></div>';

	$('.select-user-list').append($(html));
	// инициализация добавленного поля
	jsSelect2Init('.select-user-'+userSelectsCounter);
	// навесить событие на кнопку удаления
	$(`.delete-user-select-button-${userSelectsCounter}`).click(function (e) {
		deleteUserSelect(e);
	});
}

// спрятать кнопку добавления сотрудника
function hideAddEmployeeButton() {
	$('.add-user-button').hide();
	$('.users-limit-message').show();
}

// показать кнопку добавления сотрудника
function showAddEmployeeButton() {
	$('.add-user-button').show();
	$('.users-limit-message').hide();
}

// удаление селекта выбора сотрудника
function deleteUserSelect(e) {
	$(e.target).parent().parent().remove();
	showAddEmployeeButton();
}