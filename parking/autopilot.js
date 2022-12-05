"use strict";

let autopilotStep = 0; //шаги автопилота
//1 - движение вперед
//2 - поворот колес вправо
//3 - движение назад с вывернутыми вправо колесами
//4 - поворот колес влево
//5 - движение назад с вывернутыми влево колесами
//6 - поворот колес прямо

/**
 *функция запускает автопилот
 */
 function runAutopilot() {
    stoppingAutopilot = false;
    setInitPosition(false); //начальное положение машины
    autopilotStep = 1;
    autopilotForward = true;
    let intervalAutopilot = setInterval(() => autopilot(intervalAutopilot), 20);
}

/**
 *функция перемещает машину в режиме автопилота
 */
function autopilot(intervalAutopilot) {
    if (stoppingAutopilot) {
        autopilotForward = false;
        autopilotBack = false;
        autopilotRight = false;
        autopilotLeft = false;
        removeWheelBorder();
        clearInterval(intervalAutopilot); //остановка автопилота
        return;
    }
    if (autopilotStep == 1 && carPositionTop <= 14) { //остановка машины вверху, поворот колес вправо до упора 22
        autopilotForward = false;
        autopilotRight = true;
        addWheelBorder();
        autopilotStep = 2;
    }
    if (autopilotStep == 2 && angleOfRotationWheel >= limitAngleOfRotationWheel) { //остановка поворота колес вправо
        //перемещаем машину вниз с вывернутыми колесами вправо до позиции 196px от верха
        removeWheelBorder();
        autopilotRight = false;
        autopilotBack = true;
        autopilotStep = 3;
    }
    if (autopilotStep == 3 && carPositionTop >= 187) { //остановка перемещения машины вниз, поворот колес влево до упора 196
        autopilotBack = false;
        autopilotLeft = true;
        addWheelBorder();
        autopilotStep = 4;
    }
    if (autopilotStep == 4 && angleOfRotationWheel <= -limitAngleOfRotationWheel) { //остановка поворота колес влево, перемещение вниз до 372px
        removeWheelBorder();
        autopilotLeft = false;
        autopilotBack = true;
        autopilotStep = 5;
    }
    if (autopilotStep == 5 && carPositionTop >= 363) { //остановка перемещения машины вниз, поворот колес прямо 372
        autopilotBack = false;
        autopilotRight = true;
        addWheelBorder();
        autopilotStep = 6;
    }
    if (autopilotStep == 6 && angleOfRotationWheel >= 0) { //остановка поворота колес влево, остановка автопилота 
        removeWheelBorder();
        autopilotRight = false;
        autopilotStep = 0;
        clearInterval(intervalAutopilot); //остановка автопилота
    }
}