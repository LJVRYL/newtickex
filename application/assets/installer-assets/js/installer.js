var maxExec = 0;

$( document ).ready(function() {
  var stepperDiv = document.querySelector('.stepper');
  var stepper = new MStepper(stepperDiv);
});

//Validacion de terminos incorporacion de datos pre-cargados del servidor

$("#nextTerms").click( function (event) {
  if(document.getElementById("checkTerms").checked){
    //Rellena campos de hostname e ip
    $.ajax({
      cache:false,
      type: 'POST',
      url: "/dhm/setup/listparams",
      contentType: "application/json",
      dataType: "json",
      processData: false,
      success:  function(data) {   
        if(data.result){
          if (data.result.hostname){
            document.getElementById("hostname").value = data.result.hostname;
            document.getElementById("hostname").focus();
          }
          if(data.result.serverIp){
            document.getElementById("ipserv").value = data.result.serverIp;
            document.getElementById("ipserv").focus();
          }
          document.getElementById("pass").focus();
        }
      }
    });
  }
  else {
    document.getElementById("termsModal").style.display = "inline-block";
  }
});

// Cierre de modal

$("#termsModal").click( function (event) {
  document.getElementById("termsModal").style.display = "none";
});

$("#saveError").click( function (event) {
  document.getElementById("saveError").style.display = "none";
});

//Check SSL loop 

function checkSsl() {
  if (maxExec <= 60){
    $.ajax({
      cache:false,
      type: 'POST',
      url: "/dhm/setup/checksslcertinstallation",
      contentType: "application/json",
      dataType: "json",
      processData: false,
      success:  function(data) {
        if(data.result){
          setTimeout(function(){
            document.getElementById("installing").style.display = "none";
            window.location.reload();  
          }, 15000);
          
        }
        else{
          maxExec ++;
          setTimeout(checkSsl, 3000);
        }
      }
    });
  } else{
    var errorDesc = document.getElementById('result');
    document.getElementById("installing").style.display = "none";
    errorDesc.innerText = 'Ocurrió un error al intentar instalar los certificados SSL';
  }
}

//Envío de datos de configuracion

$('.installerForm').on('submit', function (event) {
    event.preventDefault();
    document.getElementById("finalizar").style.display = "none";
    document.getElementById("volver").style.display = "none";
    document.getElementById("installing").style.display = "inline-block";
    var errorDesc = document.getElementById('result');
    var theData = { "params": {
            //datos
            "hostname": document.getElementById("hostname").value,
            "serverIp": document.getElementById("ipserv").value,
            'dhmcontrolPass': document.getElementById("pass").value,
            'email': document.getElementById("email").value,
            'nsPrimary': document.getElementById("nserver1").value,
            'nsPrimaryIp': document.getElementById("nserver1ip").value,
            'nsSecondary': document.getElementById("nserver2").value,
            'nsSecondaryIp': document.getElementById("nserver2ip").value
        }};
        
        //ruta script
        $.ajax({
          cache:false,
          type: 'POST',
          url: "/dhm/setup",
          data: JSON.stringify(theData),
          contentType: "application/json",
          dataType: "json",
          processData: false,
          success:  function(data) {   
            if(data.result){
              maxExec ++;
              checkSsl();
            }
            else {
              // console.log(data.error);
              errorDesc.innerText = 'Error:' + "\n";
              data.error.data.inputException.forEach(element => errorDesc.innerText += element.errorDesc + "\n");
              // console.log('error', data.error.data.inputException[0].errorDesc);
              document.getElementById("finalizar").style.display = "inline-block";
              document.getElementById("volver").style.display = "inline-block";
              document.getElementById("installing").style.display = "none";
              document.getElementById("saveError").style.display = "inline-block";
              
            }
          },
          error: function(data) {
            // console.log(data);
            document.getElementById("finalizar").style.display = "inline-block";
            document.getElementById("volver").style.display = "inline-block";
            document.getElementById("installing").style.display = "none";
            document.getElementById("saveError").style.display = "inline-block";
            alert('Ocurrió un error al ingresar los datos de configuración.');
          }
        });
});