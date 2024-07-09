async function signIn() {

    const loginChallengeResponse = await fetch("/auth/fedcm-start", {
      method: "POST"
    });
    const loginChallenge = await loginChallengeResponse.json();
  
    const identityCredential = await navigator.credentials.get({
      identity: {
        context: "signin",
        providers: [
          {
            configURL: "any",
            type: "indieauth",
            clientId: loginChallenge.client_id,
            nonce: loginChallenge.code_challenge, // this is probably going away https://github.com/fedidcg/FedCM/issues/556
          },
        ],
        // mode: "button"
      },
    }).catch(e => {
      console.log("Error", e);
      
      document.getElementById("error-message").classList.remove("hidden");
      document.getElementById("error-message").innerText = "FedCM error: "+e.message;
    });
    console.log(identityCredential);
    if(identityCredential && identityCredential.token) {

      document.getElementById("web-sign-in").classList.add("hidden");
      document.getElementById("loading-spinner").classList.remove("hidden");
      
      const {code, metadata_endpoint} = JSON.parse(identityCredential.token);

      const response = await fetch("/auth/fedcm-login", {
        method: "POST",
        headers: {
          "Content-type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({
          code: code,
          metadata_endpoint: metadata_endpoint
        })
      });
      
      try {
        const responseData = await response.json();

        console.log(responseData);
        
        if(responseData && responseData.redirect) {
          window.location = responseData.redirect;
        } else {
          document.getElementById("error-message").classList.remove("hidden");
          document.getElementById("error-message").innerText = responseData.error;
        }

      } catch(err) {
        document.getElementById("error-message").classList.remove("hidden");
        document.getElementById("error-message").innerText = "Invalid response from server";
        return;
      }

    }
}

if(window.location.hash == '#logged-out') {
  if(navigator.credentials) {
    navigator.credentials.preventSilentAccess();
  }
  window.history.pushState({}, '', '/')
}

function getChromeVersion () {     
    var raw = navigator.userAgent.match(/Chrom(e|ium)\/([0-9]+)\./);
    return raw ? parseInt(raw[2], 10) : false;
}

if(navigator.credentials && getChromeVersion() >= 128) {
  signIn();
}

