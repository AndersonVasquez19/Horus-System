const text = "¿Quiénes Somos?";
const typingElement = document.getElementById("typing-text");

let index = 0;

function escribir() {
    if (index < text.length) {
        typingElement.textContent += text.charAt(index);
        index++;
        setTimeout(escribir, 80);
    }
}

window.addEventListener("load", escribir);

function toggleCard(card) {

    let cards = document.querySelectorAll(".card-equipo");

    cards.forEach(c => {
        if (c !== card) {
            c.classList.remove("activa");
        }
    });

    card.classList.toggle("activa");
}