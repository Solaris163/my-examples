"use strict";

let carInitPositionTop = 350; //начальное положение машины
let carInitPositionLeft = 180; //начальное положение машины
let carPositionTop = carInitPositionTop;
let carPositionLeft = carInitPositionLeft;
let angleOfRotationWheel = 0; //угол поворота передних колес
let angleOfRotationCar = 0; //угол поворота машины
let carLenght = 160; //расстояние между осями машины
let stepOfCarMoving = 1; //шаг перемещения машины в пикселях
let stepOfAngleIncrement = 0.007//шаг приращения угла поворота колес в радианах
let limitAngleOfRotationWheel = 0.6 //предельный угол поворота колес в радианах

let carEl = document.getElementById('car'); //машина
let wheel_1_el = document.getElementById('wheel_1'); //левое переднее колесо
let wheel_2_el = document.getElementById('wheel_2'); //правое переднее колесо

document.addEventListener('keydown', e => keyDownHandler(e)); //повесим слушатель на нажатие кнопки
document.addEventListener('keyup', e => keyUpHandler(e)); //повесим слушатель на отпускание кнопки

// переменные для кнопок, переменная равна true, если кнопка нажата, и равна false, если не нажата
let btnRight = false;
let btnLeft = false;
let btnForward = false;
let btnBack = false;

// дубли кнопок для автопилота
let autopilotRight = false;
let autopilotLeft = false;
let autopilotForward = false;
let autopilotBack = false;

let stoppingAutopilot = false; //остановка автопилота

/**
 *функция меняет значения переменных, при нажатии кнопок
 */
function keyDownHandler(e) {
    switch (e.code) {
        case 'ArrowUp':
            btnForward = true;
            btnBack = false; //при нажатии на кнопку, противоположная кнопка "сбрасывается", даже если она нажата
            stoppingAutopilot = true;
            break;
        case 'ArrowDown':
            btnBack = true;
            btnForward = false;
            stoppingAutopilot = true;
            break;
        case 'ArrowRight':
            btnRight = true;
            btnLeft = false;
            stoppingAutopilot = true;
            break;
        case 'ArrowLeft':
            btnLeft = true;
            btnRight = false;
            stoppingAutopilot = true;
            break;
    }
}

/**
 *функция меняет значения переменных, при отпускании кнопок
 */
function keyUpHandler(e) {
    switch (e.code) {
        case 'ArrowUp':
            btnForward = false;
            break;
        case 'ArrowDown':
            btnBack = false;
            break;
        case 'ArrowRight':
            btnRight = false;
            break;
        case 'ArrowLeft':
            btnLeft = false;
            break;
    }
}

setInterval(() => moveCar(), 20);

/**
 *функция перемещает машину в зависимости от того, какая кнопка нажата
 */
function moveCar() {
    if (btnForward || autopilotForward) {
        changeVerticalPosition(-1); //перемещаем машину по вертикали, передавая в функцию changeVerticalPosition коэффициент, равный -1
        changeHorizontalPosition(1); //перемещаем машину по горизонтали, передавая в функцию changeHorizontalPosition коэффициент, равный 1
        changeAngleOfRotationCar(1); //поворачиваем машину, передавая в функцию changeAngleOfRotationCar коэффициент, равный 1
    }
    if (btnBack || autopilotBack) {
        changeVerticalPosition(1); //перемещаем машину по вертикали, передавая в функцию changeVerticalPosition коэффициент, равный 1
        changeHorizontalPosition(-1); //перемещаем машину по горизонтали, передавая в функцию changeHorizontalPosition коэффициент, равный -1
        changeAngleOfRotationCar(-1); //поворачиваем машину, передавая в функцию changeAngleOfRotationCar коэффициент, равный -1
    }
    if ((btnRight || autopilotRight) && angleOfRotationWheel < limitAngleOfRotationWheel) { //проверяем не достигнут ли предельный угол поворота колес
        changeAngleOfRotationWheels(1); //поворачивем передние колеса, передавая в функцию changeAngleOfRotationWheels коэффициент, равный 1
    }
    if ((btnLeft || autopilotLeft) && angleOfRotationWheel > -limitAngleOfRotationWheel) { //проверяем не достигнут ли предельный угол поворота колес
        changeAngleOfRotationWheels(-1); //поворачивем передние колеса, передавая в функцию changeAngleOfRotationWheels коэффициент, равный -1
    }
}

/**
 *функция двигает машину по вертикали. Перемещение прямопропорционально косинусу угла поворота машины
 *угол берется по модулю, так как не имеет значения вправо повернута машина или влево.
 */    
function changeVerticalPosition(coefficient) {
    carPositionTop += coefficient * stepOfCarMoving * Math.cos(Math.abs(angleOfRotationCar)); //находим перемещение машины по вертикали
    carEl.style.top = `${carPositionTop}px`; //перемещаем машину по вертикали
}

/**
 *функция двигает машину по горизонтали. Перемещение прямопропорционально синусу угла поворота машины
 */
function changeHorizontalPosition(coefficient) {
    carPositionLeft += coefficient * stepOfCarMoving * Math.sin(angleOfRotationCar); //находим пермещение машины по горизонтали
    carEl.style.left = `${carPositionLeft}px`; //перемещаем машину по вертикали
}

/**
 *функция поворачивает колеса машины
 */
function changeAngleOfRotationWheels(coefficient) {
    angleOfRotationWheel += coefficient * stepOfAngleIncrement; //находим угол поворота колес
    wheel_1_el.style.transform = `rotate(${angleOfRotationWheel}rad)`; //поворачиваем левое переднее колесо
    wheel_2_el.style.transform = `rotate(${angleOfRotationWheel}rad)`; //поворачиваем правое переднее колесо
}

//Угол поворота машины вычисляется по следующей формуле:
//примем, что расстояние на которое сдвинется перед машины в сторону, равно растоянию,
//пройденному вперед, умноженному на тангенс угла поворота колес.
//Синус угла, на который повернется машина, будет равен отношению
//бокового перемещения переда машины и расстояния между осями машины,
//так как боковым перемещением задней части машины можно пренебречь.
//Следовательно угол будет равен арксинусу этого отношения.
//Боковое перемещение переда равно: stepOffCarMoving * Math.tan(angleOfRotationWheel).

/**
 *функция поворачивает машину
 */
function changeAngleOfRotationCar(coefficient) {
    angleOfRotationCar += coefficient * Math.asin(stepOfCarMoving * Math.tan(angleOfRotationWheel) / carLenght); //находим угол поворота машины
    carEl.style.transform = `rotate(${angleOfRotationCar}rad)`; //поворачиваем машину
}

/**
 *функция возвращает машину в первоначальное положение
 */
 function setInitPosition(stopAutopilot = true) {
    stoppingAutopilot = stopAutopilot;
    carPositionTop = carInitPositionTop;
    carPositionLeft = carInitPositionLeft;
    angleOfRotationWheel = 0;
    angleOfRotationCar = 0;
    carEl.style.top = `${carPositionTop}px`;
    carEl.style.left = `${carPositionLeft}px`;
    carEl.style.transform = '';
    wheel_1_el.style.transform = '';
    wheel_2_el.style.transform = '';
}

/**
 *функция добавляет рамку передним колесам
 */
 function addWheelBorder() {
    wheel_1_el.style.border = 'solid #0f0';
    wheel_2_el.style.border = 'solid #0f0';
}

/**
 *функция удаляет рамку у передних колес
 */
 function removeWheelBorder() {
    wheel_1_el.style.border = '';
    wheel_2_el.style.border = '';
}