window.kardexCalendar = (initial, available) => ({
    value: initial, available, open: false,
    month: Number(initial.slice(5,7))-1, year: Number(initial.slice(0,4)),
    get formatted() { return this.value.split('-').reverse().join('/'); },
    get monthLabel() { return new Date(this.year,this.month,1).toLocaleDateString('es',{month:'long',year:'numeric'}); },
    get days() {
        const offset=(new Date(this.year,this.month,1).getDay()+6)%7;
        const days=Array(offset).fill(null);
        for(let number=1;number<=new Date(this.year,this.month+1,0).getDate();number++) {
            days.push({number,key:`${this.year}-${String(this.month+1).padStart(2,'0')}-${String(number).padStart(2,'0')}`});
        }
        return days;
    },
    move(step) { const date=new Date(this.year,this.month+step,1);this.year=date.getFullYear();this.month=date.getMonth(); }
});
