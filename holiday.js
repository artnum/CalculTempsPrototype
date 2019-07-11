require('../Web/location/js/days.js')

let days = new Holiday(2019)
days.addYear(2018)
days.addYear(2017)
days.addYear(2020)
days.addYear(2021)
days.addYear(2022)
days.addYear(2023)

for (let k in days.holidays) {
  days.holidays[k]['vs'].forEach((day) => {
/*    console.log(day)
    let d = Date.parse(day.split('T')[0])
    console.log(d)*/
    let d = day.date
    if (d.getDay() !== 0 && d.getDay() !== 6) {
      console.log(d.toISOString().split('T')[0])
    }
  })
}
