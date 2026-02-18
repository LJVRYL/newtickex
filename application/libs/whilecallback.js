define([], function() {
    /**
     * Ejecuta un callback hasta que la condicion de stop se cumpla
     *
     * @param {function} stopCallback   En caso de devolver true, se detiene el bucle
     * @param {function} callback       Callback que se repetira en el bucle
     * @param {int} delay               Intervalo entre repeticiones
     * @param {int} abortAt             Numero de iteracion maximo del callback
     */
    var WhileCallback = function(stopCallback, callback, delay, abortAt) {
        var self = this;

        self.id = null;
        self.delay = delay || 500;
        self.abortAt = abortAt || 300;
        self.iteration = 0;

        self.id = window.setInterval(function() {
            self.iteration++;
            typeof callback === 'function' && callback();
            typeof stopCallback === 'function' && stopCallback() && window.clearTimeout(self.id);
            self.abortAt && self.abortAt <= self.iteration && window.clearTimeout(self.id);
        }, self.delay);

        return self;
    };

    return WhileCallback;
});