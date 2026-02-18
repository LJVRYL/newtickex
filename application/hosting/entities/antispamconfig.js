define(['knockout', 'ko.mapping'], function(ko, mapping) {
    var AntispamConfig = function(data) {
        'use strict';
        var self = this;

        mediator.installTo(self);
        ko.mapping = mapping;

        self.id = '';
        self.score = ko.observable(5);
        self.scoreToDelete = ko.observable(5);
        self.scoreToMove = ko.observable(5);
        self.enabled = ko.observable();
        self.subjectTag = ko.observable('****SPAM****');
        self.deletespam = ko.observable(0);
        ko.mapping.fromJS(data, {}, this);

        /**
         * fixes para evitar errores de backend
         */
        self.scoreToMove.subscribe(function(value) {
            if (parseInt(value) > parseInt(self.scoreToDelete())) {
                self.scoreToDelete(value);
            }
        });
        self.scoreToDelete.subscribe(function(value) {
            if (parseInt(value) < parseInt(self.scoreToMove())) {
                if (value == 10){
                    self.scoreToMove(9);    
                } else {
                    self.scoreToMove(value);
                }
            }
        });

        self.scoreText = function() {
            var labels = {
                1: "Extremadamente restrictivo +",
                2: "Extremadamente restrictivo",
                3: "El mas restrictivo",
                4: "Muy restrictivo",
                5: "Valor recomendado",
                6: "Bastante permisivo",
                7: "Permisivo",
                8: "Muy permisivo",
                9: "El mas permisivo",
                10: "Extremadamente permisivo",
                11: "Extremadamente permisivo +",
                12: "Extremadamente permisivo ++"
            };
            return (labels[self.score()] ?
                labels[self.score()] :
                '') + ' ('+self.score()+')';
        };

        self.scoreToMoveText = function() {
            var labels = {
                3: "El mas restrictivo",
                4: "Muy restrictivo",
                5: "Valor recomendado",
                6: "Bastante permisivo",
                7: "Permisivo",
                8: "Muy permisivo",
                9: "El mas permisivo"
            };
            return (labels[self.scoreToMove()] ?
                labels[self.scoreToMove()] :
                '') + ' ('+self.scoreToMove()+')';
        };

        self.scoreToDeleteText = function() {
            var labels = {
                4: "El mas restrictivo",
                5: "Muy restrictivo",
                6: "Bastante restrictivo",
                7: "Poco restrictivo",
                8: "Valor recomendado",
                9: "Permisivo",
                10: "El mas permisivo"
            };
            return (labels[self.scoreToDelete()] ?
                labels[self.scoreToDelete()] :
                '') + ' ('+self.scoreToDelete()+')';
        };
        //self.scoreToDeleteText = function() {
        //    var labels = {
        //        5: "El mas restrictivo",
        //        6: "Muy restrictivo",
        //        7: "Restrictivo",
        //        8: "Bastante restrictivo",
        //        9: "Poco restrictivo",
        //        10: "Valor recomendado",
        //        11: "Permisivo",
        //        12: "El mas permisivo"
        //    };
        //    return (labels[self.scoreToDelete()] ?
        //        labels[self.scoreToDelete()] :
        //        '') + ' ('+self.scoreToDelete()+')';
        //};
    };

    return AntispamConfig;
});