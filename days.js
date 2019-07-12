/* eslint-env amd */
'use strict';

(function () {
  var global = Function('return this')() // eslint-disable-line
  if (typeof globalHoliday === 'undefined') {
    global.Holiday = {}
  }

  global.Holiday = (function () {
    const DAY_LENGTH_MS = 86400000
    var EasterRelative = {
      'shroveTuesday': -47, // Mardi gras, fin de Carnaval
      'passionSunday': -14, // Dimanche de la passion
      'palmSunday': -14, // Dimanche des Rameaux
      'maundyThursday': -3, // Jeudi Saint
      'goodFriday': -2, // Vendredi Saint
      'easter': 0, // Pâques (toujours un dimanche)
      'easterMonday': 1, // Lundi de Pâques
      'ascensionDay': 39, // Ascension
      'pentecost': 49, // Pentecôte
      'pentecostMonday': 50, // Lundi de Pentecôte
      'trinitySunday': 56, // Sainte Trinité
      'corpusChristi': 60 // Fête-Dieu
    }
    /* pour un mois donnée (month 1 - 12), un jour donné (1: lundi - 7:dimanche), le "count" ième et un décalage (offset) */
    var RelativeDate = {
      'fahrtsfest': { month: 4, weekday: 4, count: 1, offset: 0 }, // Bataille, canton de Glaris
      'jeuneGenevois': { month: 9, weekday: 7, count: 1, offset: 4 }, // GE
      'jeuneFederal': { month: 9, weekday: 7, count: 3, offset: 0 }, // Suisse
      'lundiDuJeune': { month: 9, weekday: 7, count: 3, offset: 1 } // Lundi du jeûne fédéral      
    }
    var FixedDay = {
      'newYear': '1.1', // Nouvel-An
      'berchtoldsTag': '2.1', // Saint-Berchtoldstag
      'epiphany': '6.1', // Épiphanie
      'instaurationRepublique': '1.3', // Instauration République de Neuchâtel
      'saintJoseph': '19.3', // Saint-Joseph
      'labourDay': '1.5', // Fête du travail
      'victoireAllie': '8.5', // Victoire des alliées (FRA)
      'nationalFRDay': '14.7', // Fête nationale FRA
      'commemorationPlebiscite': '23.6', // Commémoration du Plébiscite (Jura)
      'nationalCHDay': '1.8', // Fête nationale CHE
      'assumption': '15.8', // Assomption
      'saintNicolas': '25.9', // Saint-Nicolas de Flue
      'allSaintsDay': '1.11', // Toussaint
      'armistice': '11.11', // Armistice (FRA)
      'immaculateConception': '8.12', // Immaculée Conception
      'christmasEve': '24.12', // Veille de Noël
      'christmas': '25.12', // Noël
      'saintEtienne': '26.12', // Saint Étienne
      'restaurationRepublique': '31.12', // Restauration République Genêve
      'newYearEve': '31.12' // Veille de Nouvel-an
    }

    /* Indiquer un jour se fait de la sorte :
       <nomDuJour>:<type>.
       le type peut être 'f' pour férié, 'c' pour chômé, 'c2' pour chômé dès midi.
       Dans le cas où un jour doit être remplacer par un autre s'il tombe un dimanche (ou un autre jour de la semaine), on l'indique de la sorte :
       <nomDuJour><numéroDuJour>|<nomDuJourdeRemplacement>:<type>
       la séquence <numéroDuJour>|<nomDuJourdeRemplacement> peut se répéter à l'infini.
       le numéro du jour va de 1 à 7 ou 1 est lundi et 7 est dimanche.
     */
    var cantons = {
      'all': [ 'newYear:f', 'ascensionDay:f', 'christmas:f' ],
      'vs': [ 'nationalCHDay:f', 'saintJoseph:f', 'corpusChristi:f', 'assumption:f', 'allSaintsDay:f', 'immaculateConception:f' ],
      'vd': [ 'nationalCHDay:f', 'goodFriday:f', 'easterMonday:f', 'pentecostMonday:f', 'lundiDuJeune:f' ],
      'ge': [ 'nationalCHDay:f', 'goodFriday:f', 'easterMonday:f', 'pentecostMonday:f', 'jeuneGenevois:f', 'restaurationRepublique:f' ],
      'ju': [ 'nationalCHDay:f', 'easter:f', 'easterMonday:f', 'pentecostMonday:f', 'labourDay:f', 'goodFriday:f', 'corpusChristi:f', 'assumption:f', 'jeuneFederal:f', 'allSaintsDay:f', 'berchtoldsTag:f', 'commemorationPlebiscite:f' ],
      'ne': [ 'nationalCHDay:f', 'newYear7|berchtoldsTag:f', 'instaurationRepublique:f', 'goodFriday:f', 'ascensionDay:f', 'labourDay:f', 'christmas7|saintEtienne:f' ],
      'fr-cat': [ 'nationalCHDay:f', 'goodFriday:f', 'corpusChristi:f', 'assumption:f', 'allSaintsDay:f', 'immaculateConception:f' ],
      'fr-prot': [ 'nationalCHDay:f', 'berchtoldsTag:f', 'goodFriday:f', 'easterMonday:f', 'pentecostMonday:f', 'saintEtienne:f' ],
      'be': [ 'nationalCHDay:f', 'berchtoldsTag:f', 'goodFriday:f', 'easterMonday:f', 'pentecostMonday:f', 'saintEtienne:f' ],
      'fra': [ 'easterMonday:f', 'labourDay:f', 'pentecostMonday:f', 'nationalFRDay:f', 'assumption:f', 'allSaintsDay:f', 'armistice:f', 'victoireAllie:f' ]
    }

    var computeYear = function (year) {
      let days = {}
      let common = []
      cantons['all'].forEach((day) => {
        let [name, type] = day.split(':', 2)
        let result = computeDay(name, year)
        if (result) {
          common.push({name: name, date: result, type: type})
        }
      })
      for (let c in cantons) {
        if (c === 'all') { continue }
        let current = []
        let replaced = []
        cantons[c].forEach((day) => {
          let [name, type] = day.split(':', 2)
          let result = null
          /* certains cantons (NE uniquement) remplace automatiquement des jours feriées par un autre s'il tombe le dimanche */
          if (name.indexOf('|') !== -1) {
            name = `${name}9` // add a stop number
            let rDays = name.split('|')
            for (let i = 0; i < rDays.length; i++) {
              let day = parseInt(rDays[i].substring(rDays[i].length - 1))
              let r = computeDay(rDays[i].substring(0, rDays[i].length - 1), year)
              replaced.push(rDays[i].substring(0, rDays[i].length - 1))
              if (day === 9) {
                // reach the last replacement day
                result = r
                name = rDays[i].substring(0, rDays[i].length - 1)
                break
              }

              if (day === 7) { day = 0 }
              if (r && r.getDay() !== day) {
                result = r
                name = rDays[i].substring(0, rDays[i].length - 1)
                break
              }
            }
          }
          if (result === null) {
            result = computeDay(name, year)
          }
          if (result) {
            current.push({name: name, date: result, type: type})
          }
        })
        days[c] = [...current]
        common.forEach((d) => {
          let add = true
          for (let i = 0; i < current.length; i++) {
            if (replaced.indexOf(d.name) !== -1) {
              add = false
              break
            }
            if (current[i].name === d.name) {
              add = false
              break
            }
          }
          if (add) {
            days[c].push(d)
          }
        })
        days[c].sort((a, b) => a.date.getTime() - b.date.getTime())
      }
      return days
    }

    var computeDay = function (name, year) {
      let func = findComputeFunction(name)
      if (func) {
        return func(name, year)
      }
      return null
    }
    
    var findComputeFunction = function (name) {
      if (EasterRelative[name]) { return computeEasterDay }
      if (FixedDay[name]) { return computeFixedDay }
      if (RelativeDate[name]) { return computeRelativeDay }
      return null
    }
    
    var computeEasterDay = function (name, year) {
      if (!EasterRelative[name]) { return null }
      let rule = EasterRelative[name]
      let date = _easterDay(year)
      date.setTime(date.getTime() + (rule * DAY_LENGTH_MS))
      return date
    }

    var computeFixedDay = function (name, year) {
      if (!FixedDay[name]) { return null }
      let rule = FixedDay[name]
      let [day, month] = rule.split('.', 2)
      return new Date(year, parseInt(month) - 1, day, 12, 0, 0, 0)
    }

    var computeRelativeDay = function (name, year) {
      if (!RelativeDate[name]) { return null }
      let rule = RelativeDate[name]
      let date = new Date(year, rule.month - 1, 0, 12, 0, 0, 0) // start the day before the beginning of the month

      /* find weekday */
      let currentDay = 0
      do {
        date.setTime(date.getTime() + DAY_LENGTH_MS)
        currentDay = date.getDay()
        if (currentDay === 0) {
          currentDay = 7
        }
      } while (currentDay !== rule.weekday)
      /* go to rule.count (first, second, third, ...) week day */
      for (let i = 1; i < rule.count; i++) {
        date.setTime(date.getTime() + (DAY_LENGTH_MS * 7))
      }
      /* go to day offset (the third day after the third sunday for example) */
      for (let i = 0; i < rule.offset; i++) {
        date.setTime(date.getTime() + DAY_LENGTH_MS)
      }
      return date
    }

    var _easterDay = function (year) {
      let C = Math.floor(year / 100)
      let N = year - 19 * Math.floor(year / 19)
      let K = Math.floor((C - 17) / 25)
      let I = C - Math.floor(C / 4) - Math.floor((C - K) / 3) + 19 * N + 15
      I = I - 30 * Math.floor((I / 30))
      I = I - Math.floor(I / 28) * (1 - Math.floor(I / 28) * Math.floor(29 / (I + 1)) * Math.floor((21 - N) / 11))
      let J = year + Math.floor(year / 4) + I + 2 - C + Math.floor(C / 4)
      J = J - 7 * Math.floor(J / 7)
      let L = I - J
      let month = 3 + Math.floor((L + 40) / 44)
      var day = L + 28 - 31 * Math.floor(month / 4)

      return new Date(year, month - 1, day, 12, 0, 0)
    }

    function Holiday (year) {
      this.holidays = {}
      this.rHolidays = {}
      this.addYear(year)
    }

    Holiday.prototype.addYear = function (year) {
      if (this.holidays[year]) { return }
      this.holidays[year] = computeYear(year)
      for (let c in this.holidays[year]) {
        this.holidays[year][c].forEach((e) => {
          let day = e.date.toISOString().split('T')[0]
          if (!this.rHolidays[day]) {
            this.rHolidays[day] = [e]
          }
          this.rHolidays[day].push(c)
        })
      }
    }

    Holiday.prototype.isHoliday = function (day) {
      if (typeof day === 'object') {
        day = day.toISOString().split('T')[0]
      }
      let days = this.rHolidays[day]
      if (days) {
        days = [...days]
        let details = days.shift()
        return {day: details, c: days}
      }

      return false
    }

    Holiday.prototype.searchHoliday = function (begin, end) {
      let b = begin.toISOString().split('T')[0]
      let e = end.toISOString().split('T')[0]
      let res = []
      for (let k of this.rHoliday()) {
        if (k >= b && k <= e) {
          let days = [...this.rHolidays[k]]
          res.push({day: days.shift(), c: days})
        }
      }
      return res
    }
    
    return Holiday
  }())
}())
