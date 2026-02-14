<!-- 🔔 Beep Audio -->
<audio id="beep" preload="auto">
    <source src="../assets/beep.mp3" type="audio/mpeg">
</audio>

<script>
let totalSeconds = 0;
let timerInterval = null;

function startTimer(minutes) {
    totalSeconds = minutes * 60;

    const timer = document.getElementById("timer");
    const beep  = document.getElementById("beep");

    timerInterval = setInterval(() => {

        let min = Math.floor(totalSeconds / 60);
        let sec = totalSeconds % 60;

        timer.innerHTML =
            "Time Left: " + min + ":" + (sec < 10 ? "0" + sec : sec);

        /* 🔔 Beep every second in last 15 seconds */
        if (totalSeconds <= 15 && totalSeconds > 0) {
            beep.currentTime = 0;
            beep.play().catch(() => {});
        }

        /* ⏰ Time over → auto submit */
        if (totalSeconds === 0) {
            clearInterval(timerInterval);
            document.getElementById("examForm").submit();
            return;
        }

        totalSeconds--;

    }, 1000);
}
</script>
